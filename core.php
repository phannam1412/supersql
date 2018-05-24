<?php

namespace SuperSql;

use const PHP_EOL;
use const PHP_INT_MAX;
use const PHP_INT_MIN;
use PHPSQLParser\PHPSQLParser;
use function str_repeat;

class SuperSql {

    private $table = [];
    private $parsed = [];
    private $from_table_alias_to_full_name = [];
    private $from_table_name_to_alias = [];
    private $full_column_names = [];
    private $total = 0;
    private $column_alias = [];

    # Hook add more columns for table.
    private static $more_columns = [];
    public static function defineMoreColumnsForTable($table, $callback) {
        if(!array_key_exists($table,self::$more_columns))
            self::$more_columns[$table] = [];
        self::$more_columns[$table][] = $callback;
    }

    # Hook add more table.
    private static $more_tables = [];
    private static $cache = [];
    public static function defineSelectFromTable($table, $callback, $cache = 0) {
        self::$more_tables[$table] = $callback;
        self::$cache[$table] = $cache;
    }

    private function preventArrayInColumn($rows) {
        foreach($rows as $key => $row)
            foreach($row as $key2 => $col)
                if(is_array($col))
                    $rows[$key][$key2] = json_encode($col);
        return $rows;
    }

    # Function NOW().
    private function funcNow() {
        return date('Y-m-d H:i:s');
    }

    # Function CURRENT_TIMESTAMP().
    # Exactly the same as NOW().
    private function funcCurrent_timestamp() {
        return date('Y-m-d H:i:s');
    }

    # Function TRUNCATE().
    # There is a bug in sql lexer that see TRUNCATE as a reserved keyword.
//	private function funcTruncate($value) {
//		return floatval(number_format($value[0], $value[1], '.', ''));
//	}

    # Function ROUND().
    private function funcRound($value) {
        return floatval(number_format($value[0], $value[1], '.', ''));
    }

    private function funcSubstr($params) {
        return substr($params[0], $params[1], $params[2]);
    }

    # Function CONCAT().
    private function funcConcat($params) {
        return implode('',$params);
    }

    # Function ABS().
    private function funcAbs($value) {
        return abs($value);
    }

    # Function DATE().
    private function funcDate($datetime_str) {
        return date('Y-m-d', strtotime($datetime_str));
    }

    # Function TIME().
    private function funcTime($datetime_str) {
        return date('H:i:s', strtotime($datetime_str));
    }

    # Function REPLACE().
    private function funcReplace($params) {
        return str_ireplace($params[1], $params[2], $params[0]);
    }

    # Function FROM_UNIXTIME().
    private function funcFrom_unixtime($value) {
        return date('c', $value);
    }

    # Function FLOOR().
    private function funcFloor($value) {
        return floor($value);
    }

    # Function UNIX_TIMESTAMP().
    private function funcUnix_timestamp($value = null) {
        if($value === null) return time();
        return strtotime($value);
    }

    # Function IF
    private function funcIf($params) {
        return $params[0] ? $params[1] : $params[2];
    }

    # Function YEAR
    private function funcYear($date_str) {
        return date('Y',strtotime($date_str));
    }

    # Function MONTH
    private function funcMonth($date_str) {
        return date('m',strtotime($date_str));
    }

    # Aggregate Function COUNT().
    private function aggregateFuncCount($parent_row, $param) {

        if(array_key_exists($param,$parent_row)) return 1;

        return count($parent_row);
    }

    # Aggregate Function SUM().
    private function aggregateFuncSum($parent_row, $param) {

        if(array_key_exists($param,$parent_row)) return $parent_row[$param];

        $sum = 0;
        foreach($parent_row['children'] as $key => $row) $sum += floatval($row[$param]);
        return $sum;
    }

    # Aggregate Function MAX().
    private function aggregateFuncMax($parent_row, $param) {

        if(array_key_exists($param,$parent_row)) return $parent_row[$param];

        $max = PHP_INT_MIN;
        foreach($parent_row['children'] as $key => $row)
            if($max < floatval($row[$param]))
                $max = floatval($row[$param]);
        return $max;
    }

    # Aggregate Function MIN().
    private function aggregateFuncMin($parent_row, $param) {

        if(array_key_exists($param,$parent_row)) return $parent_row[$param];

        $min = PHP_INT_MAX;
        foreach($parent_row['children'] as $key => $row)
            if($min > floatval($row[$param]))
                $min = floatval($row[$param]);
        return $min;
    }

    # Aggregate Function avg().
    private function aggregateFuncAvg($parent_row, $param) {

        if(array_key_exists($param,$parent_row)) return $parent_row[$param];

        $sum = 0;
        foreach($parent_row['children'] as $key => $row) $sum += floatval($row[$param]);
        return $sum / count($parent_row);
    }

    private function getColumnValue($struct, $row) {

        if(!is_array($struct))
            throw new \Exception('The struct is not an array when getting column value');

        if(!array_key_exists('expr_type',$struct)) return $this->evaluateExpression($struct);

        $expr_type = array_key_exists('expr_type',$struct) ? $struct['expr_type'] : 'colref';

        if($expr_type === 'bracket_expression' || $expr_type === 'expression')
            return $this->evaluateExpression($struct['sub_tree'], $row);

        # Aggregate function
        if($expr_type === 'aggregate_function') {

            $func = 'aggregateFunc' . ucwords($struct['base_expr']);

            $param = null;
            if(!empty($struct['sub_tree'])) {
                $param = $this->toColumnNameWithPrefix($this->getColumnName(reset($struct['sub_tree'])));
            }

            return $this->$func($row, $param);
        }

        # Function.
        if($expr_type === 'function') {
            $func = 'func' . ucwords($struct['base_expr']);

            if(!empty($struct['sub_tree'])) {
                $params = $this->getFunctionParams($struct['sub_tree'], $row);
                return $this->$func(count($params) > 1 ? $params : reset($params));
            }

            return $this->$func();
        }

        # Column.
        if($expr_type === 'colref') {

            # Allow using all alias that represent an expression.
            if(array_key_exists($struct['base_expr'],$this->column_alias)
                && (
                    ($this->column_alias[$struct['base_expr']]['expr_type'] === 'expression')
                    || ($this->column_alias[$struct['base_expr']]['expr_type'] === 'bracket_expression')
                    || ($this->column_alias[$struct['base_expr']]['expr_type'] === 'function')
                )
            ) {
                return $this->getColumnValue($this->column_alias[$struct['base_expr']], $row);
            }

            $column_name = $this->toColumnNameWithPrefix($struct['base_expr'], $row);
            if(!array_key_exists($column_name,$row))
                throw new \Exception("Column $column_name does not exist");
            return $row[$column_name];
        }

        # Constant.
        if($expr_type === 'const') {
            return trim($struct['base_expr'], "'");
        }

        # Alias.
        if($expr_type === 'alias') {
            $column_name = $struct['base_expr'];
            if(!array_key_exists($column_name,$this->column_alias))
                throw new \Exception('Unknown alias: ' . $struct['base_expr']);
            return $this->getColumnValue($this->column_alias[$column_name], $row);
        }

        # Bracket Expression.
        if($expr_type === 'bracket_expression') {
            return $this->evaluateExpression($struct['sub_tree'], $row);
        }

        # Bracket Expression.
        if($expr_type === 'subquery') {
            return SuperSql::execute(trim($struct['base_expr'],"()"));
        }

        throw new \Exception('unknown column: ' . $struct['base_expr'] . ' when getting column value');
    }

    private function getValuesInList($structs) {
        $result = [];
        foreach($structs as $struct) $result[] = $this->getColumnValue($struct, []);
        return $result;
    }

    private function getFunctionParams($multiple_expressions, $row) {
        $params = [];
        if(!is_array($multiple_expressions))
            $params[] = $this->evaluateExpression($multiple_expressions, $row);
        else
            foreach($multiple_expressions as $expression)
                $params[] = $this->evaluateExpression($expression, $row);
        return $params;
    }


    private function evaluateExpression($structs, $row = []) {

        # Singular struct ?
        if(array_key_exists('base_expr', $structs)) return $this->getColumnValue($structs, $row);

        # Singular struct ?
        if(!array_key_exists('base_expr', $structs) && count($structs) === 1) return $this->getColumnValue($structs[0], $row);

        # .. BETWEEN .. AND ...
        if(count($structs) >= 5
            && strtolower($structs[1]['base_expr']) === 'between'
            && strtolower($structs[3]['base_expr']) === 'and'
        ) {
            $result1 = $this->applyBetweenAnd($structs, $row);

            if(count($structs) === 5) {
                return $result1;
            }

            if(count($structs) >= 7) {
                $result2 = $this->evaluateExpression(array_slice($structs, 6), $row);

                $operator = strtolower($structs[5]['base_expr']);

                if($operator === 'and') return $result1 && $result2;
                if($operator === 'or') return $result1 || $result2;

                throw new \Exception("unknown operator $operator");
            }

            throw new \Exception("unknown expression: " . json_encode($structs));
        }


        # .. AND .. OR ..
        for($a=0;$a<count($structs) ; $a++) {
            if(strtolower($structs[$a]['base_expr']) === 'and' || strtolower($structs[$a]['base_expr']) === 'or') {

                $operator = strtolower($structs[$a]['base_expr']);

                $result1 = $this->evaluateExpression(array_slice($structs, 0, $a), $row);
                $result2 = $this->evaluateExpression(array_slice($structs, $a + 1, count($structs) - $a - 1), $row);

                if($operator === 'and') return $result1 && $result2;
                if($operator === 'or') return $result1 || $result2;

                throw new \Exception("unknown operator $operator");
            }
        }

        # Bracket .. ( .. ) ..
        if(count($structs) === 1 && $structs[0]['expr_type'] === 'bracket_expression')
            return $this->evaluateExpression($structs[0]['sub_tree'], $row);

        # Comparision Operator.
        if(count($structs) >= 3
            && $structs[1]['expr_type'] === 'operator'
            && in_array($structs[1]['base_expr'],['>','<','>=','<=','=','!=','<>'])) {
            return $this->applyComparision($structs, $row);
        }

        # Calculation Operator.
        if(count($structs) >= 3
            && $structs[1]['expr_type'] === 'operator'
            && in_array($structs[1]['base_expr'],['+','-','*','/'])) {
            $left = $this->getColumnValue($structs[0], $row);
            $operator = $structs[1]['base_expr'];
            $right = $this->evaluateExpression(array_slice($structs, 2), $row);
            $left = floatval($left);
            $right = floatval($right);
            if($operator === '+') return $left + $right;
            if($operator === '-') return $left - $right;
            if($operator === '*') return $left * $right;
            if($operator === '/') {
                if(empty($right)) return 0;
                return $left / $right;
            }
            throw new \Exception("Unknown operator $operator when evaluating expression");
        }

        # IS operator.
        if(count($structs) >= 3
            && $structs[1]['expr_type'] === 'operator'
            && strtolower($structs[1]['base_expr']) === 'is'
            && strtolower($structs[2]['expr_type']) === 'const') {
            $left = $this->getColumnValue($structs[0], $row);
            $mapping = [
                'null' => null,
                'true' => true,
                'false' => false,
            ];
            $right = $mapping[$structs[2]['base_expr']];
            return $left == $right;
        }

        # IS NOT operator.
        if(count($structs) >= 4
            && $structs[1]['expr_type'] === 'operator'
            && $structs[2]['expr_type'] === 'operator'
            && strtolower($structs[1]['base_expr']) === 'is'
            && strtolower($structs[2]['base_expr']) === 'not'
            && strtolower($structs[3]['expr_type']) === 'const'
        ) {
            $left = $this->getColumnValue($structs[0], $row);
            $mapping = [
                'null' => null,
                'true' => true,
                'false' => false,
            ];
            $right = $mapping[$structs[3]['base_expr']];
            return $left != $right;
        }

        # IN operator in list.
        if(count($structs) >= 3
            && $structs[1]['expr_type'] === 'operator'
            && strtolower($structs[1]['base_expr']) === 'in'
            && strtolower($structs[2]['expr_type']) === 'in-list') {
            $left = $this->getColumnValue($structs[0], $row);
            $right = $this->getValuesInList($structs[2]['sub_tree'], $row);
            return in_array(strtolower($left), $right);
        }

        # IN operator in subquery.
        if(count($structs) >= 3
            && $structs[1]['expr_type'] === 'operator'
            && strtolower($structs[1]['base_expr']) === 'in'
            && strtolower($structs[2]['expr_type']) === 'subquery') {
            $left = $this->getColumnValue($structs[0], $row);

            # @todo should implement cache for subquery.
            $right = $this->evaluateExpression($structs[2], $row);
            $right = array_map(function($row){
                return reset($row);
            }, $right);
            return in_array(strtolower($left), $right);
        }

        # NOT IN operator in list.
        if(count($structs) >= 4
            && $structs[1]['expr_type'] === 'operator'
            && strtolower($structs[1]['base_expr']) === 'not'
            && strtolower($structs[2]['base_expr']) === 'in'
            && strtolower($structs[3]['expr_type']) === 'in-list') {
            $left = $this->getColumnValue($structs[0], $row);
            $right = $this->getValuesInList($structs[2]['sub_tree'], $row);
            return !in_array(strtolower($left), $right);
        }

        # NOT IN operator in subquery.
        if(count($structs) >= 4
            && $structs[1]['expr_type'] === 'operator'
            && strtolower($structs[1]['base_expr']) === 'not'
            && strtolower($structs[2]['base_expr']) === 'in'
            && strtolower($structs[3]['expr_type']) === 'subquery') {
            $left = $this->getColumnValue($structs[0], $row);

            # @todo should implement cache for subquery.
            $right = $this->evaluateExpression($structs[3], $row);
            $right = array_map(function($row){
                return reset($row);
            }, $right);
            return !in_array(strtolower($left), $right);
        }

        throw new \Exception('Unknown expression: ' . json_encode($structs));

    }

    private function applyComparision($condition, $row) {
        $operator = $condition[1]['base_expr'];
        $left = $this->getColumnValue($condition[0], $row);
        $right = $this->getColumnValue($condition[2], $row);
        $left = is_string($left) ? strtolower($left) : $left;
        $right = is_string($right) ? strtolower($right) : $right;
        if($operator === '=') return $left == $right;
        if($operator === '>') return $left > $right;
        if($operator === '<') return $left < $right;
        if($operator === '>=') return $left >= $right;
        if($operator === '<=') return $left <= $right;
        if($operator === '!=') return $left != $right;
        if($operator === '<>') return $left != $right;

        throw new \Exception("unknown operator " . $operator);
    }

    private function applyBetweenAnd($condition, $row) {
        $value = $this->getColumnValue($condition[0], $row);
        $from = $this->getColumnValue($condition[2], $row);
        $to = $this->getColumnValue($condition[4], $row);
        return $value >= $from && $value <= $to;
    }

    private function applyWhere($condition) {
        foreach($this->table as $key => $value) {
            $result = $this->evaluateExpression($condition, $value);
            if($result === false) unset($this->table[$key]);
        }
        $this->table = array_values($this->table);
    }

    private function outerJoin($table, $condition) {
        if(count($table) === 0) return;
        if(count($this->table) === 0) {
            $this->table = $table;
            return;
        }
        $new = [];
        for($a=0;$a<count($this->table);$a++) {
            for($b=0;$b<count($table);$b++) {
                $row = array_merge($this->table[$a], $table[$b]);
                $result = true;
                if(!empty($condition))
                    $result = $this->evaluateExpression($condition, $row);
                if($result === true) $new[] = $row;
            }
        }
        $this->table = array_values($new);
    }

    private static $hook_will_select_from_table;
    public static function hookWillSelectFromTable($callback) {
        self::$hook_will_select_from_table = $callback;
    }

    private function loadTableRows($table_name) {

        # Try loading table from cache if possible.
        $table = null;
        $cache_file = "cache/$table_name.txt";
        if(!empty(self::$cache[$table_name]) && file_exists($cache_file)) {
            $cache_duration_in_seconds = self::$cache[$table_name];
            $filemtime = filemtime($cache_file);
            $expire_time = $filemtime + $cache_duration_in_seconds;

            # Cache still valid ?
            if($expire_time > time()) {
                $text = file_get_contents($cache_file);
                if(!empty($text)) $table = json_decode($text, true);
            }
        }

        # Table hasn't been loaded yet ?
        if($table === null) {

            # Allow hooking before loading a table.
            if(is_callable(self::$hook_will_select_from_table)) {
                $hook = self::$hook_will_select_from_table;
                $hook($table_name);
            }

            if(!array_key_exists($table_name,self::$more_tables))
                throw new \Exception("Table $table_name does not exist");

            $func = self::$more_tables[$table_name];

            $table = $func();
            if(!empty(self::$cache[$table_name])) {
                file_put_contents($cache_file, json_encode($table));
                chmod($cache_file, 0777);
            }
        }

        $table = $this->preventArrayInColumn($table);

        # Hook
        # Allow plugins to add more columns.
        if(array_key_exists($table_name,self::$more_columns)) {
            foreach($table as $key => $row) {
                $more = [];
                foreach(self::$more_columns[$table_name] as $func)
                    $more = array_merge($more, $func($row));
                $row = array_merge($row, $more);
                $table[$key] = $row;
            }
        }

        # Add table name as prefix to all columns name to ensure no column has the same name.

        $prefix = $table_name;
        $alias = $this->getTableAlias($table_name);
        if($alias !== false) $prefix = $alias;

        foreach($table as $key => $row) {
            foreach($row as $key3 => $col) {
                $row[$prefix . '.' . strtolower($key3)] = $col;
                unset($row[$key3]);
            }
            $table[$key] = $row;
        }
        return array_values($table);
    }

    private function isColumnNameUnique($column_name) {
        if(!array_key_exists($column_name,$this->full_column_names)) return true;
        return count($this->full_column_names[$column_name]) < 2;
    }

    private function removeColumnPrefixIfPossible(&$rows) {
        foreach($rows as $key => $row)
            $rows[$key] = $this->removeColumnPrefixForRow($row);
    }

    private function removeColumnPrefixForRow($row) {
        $new = [];
        foreach($row as $column_name => $value) {

            # Column alias doesn't have table name prefix.
            if(strpos($column_name,'.') === false) {
                $new[$column_name] = $value;
                continue;
            }

            list ($table_prefix, $col) = explode('.', $column_name);

            # Is unique column name ? Remove table name prefix.
            if($this->isColumnNameUnique($col))
                $new[$col] = $value;

            # Keep table name prefix to prevent column name conflict.
            else
                $new[$table_prefix . '.' . $col] = $value;
        }
        return $new;
    }

    # Get a mapping of short column names (without table alias prefix)
    # to full column names (with table alias prefix).
    private function constructColumnNameMapping($row) {

        if(empty($row)) return;

        $collection = [];
        foreach($row as $column => $value) {
            if(strpos($column, '.') === false) continue;
            list($alias, $col) = explode('.', $column);
            if(!array_key_exists($col,$collection)) $collection[$col] = [];
            $collection[strtolower($col)][] = strtolower($column);
        }
        $this->full_column_names = $collection;
    }

    private function getTableAlias($table_name) {
        return array_search($table_name, $this->from_table_alias_to_full_name);
    }

    private function applyFrom($from) {

        $load_first_table = false;

        # Join all tables.
        foreach($from as $key => $struct) {

            $table_name = $struct['table'];

            # Get table alias if any.
            if(!empty($struct['alias'])) {
                $alias                                       = $struct['alias']['name'];
                $this->from_table_alias_to_full_name[$alias] = $table_name;
                $this->from_table_name_to_alias[$table_name] = $alias;
            }

            # If we already load first table and it's empty,
            # so we don't need to do any JOIN because the final result is always empty.
            if($load_first_table && empty($this->table)) return;

            # Outer join 2 tables to produce a new bigger table.
            $this->outerJoin($this->loadTableRows($table_name), $struct['ref_clause']);

            $this->constructColumnNameMapping(reset($this->table));

            $load_first_table = true;
        }
    }

    # Convert short column name to full column name (with table prefix) if possible.
    # The already-full column name is kept as is.
    private function toColumnNameWithPrefix($column_name) {

        # Special case.
        if($column_name === '*') return $column_name;

        # Is full column name ? Ensure that this column exist.
        if(strpos($column_name, '.') !== false) {

            list ($table_prefix, $col) = explode( '.', $column_name);

            # Table alias.
            return $this->toTableAliasIfPossible($table_prefix) . '.' . $col;

        }

        # Convert short column name to full column name.
        if(!array_key_exists($column_name,$this->full_column_names)) {
            throw new \Exception("Column $column_name does not exist");
        }


        if(count($this->full_column_names[$column_name]) > 1)
            throw new \Exception("Column $column_name is ambigious");

        return $this->full_column_names[$column_name][0];
    }

    private function getColumnName($struct) {
        if(!empty($struct['alias'])) return $struct['alias']['name'];
        return $struct['base_expr'];
    }

    private function toTableAliasIfPossible($table_prefix) {
        if(
            !array_key_exists($table_prefix,self::$more_tables) # Not a table name
            && !array_key_exists($table_prefix,$this->from_table_alias_to_full_name) # Not an alias
        )
            throw new \Exception("Table or alias $table_prefix does not exist");

        # Table name.
        if(array_key_exists($table_prefix,self::$more_tables))
            return $this->getTableAlias($table_prefix);

        # Table alias.
        return $table_prefix;
    }

    # SELECT
    private function applySelect($select) {

        # Special case: only one select and the select is aggregate function.
        if(count($select) === 1 && $select[0]['expr_type'] === 'aggregate_function' && count($this->table) === 0) {
            $this->table = [[$this->getColumnName($select[0]) => 0]];
            return;
        }

        # Special case: only one select and the select is aggregate function.
        if(count($select) === 1 && $select[0]['expr_type'] === 'aggregate_function') {
            $this->table = [
                ['children' => $this->table],
            ];
        }

        $new = [];
        foreach($this->table as $row) {
            $cols = [];
            foreach($select as $struct) {

                $column_name_without_alias = $struct['base_expr'];

                # Special case: *
                if(strpos($column_name_without_alias, '*') !== false && $struct['expr_type'] === 'colref') {

                    # Select in a table.
                    if(strpos($column_name_without_alias, '.') !== false) {
                        list ($table_prefix, $tmp) = explode('.', $column_name_without_alias);
                        $table_name = $this->toTableAliasIfPossible($table_prefix);

                        $tmp = [];
                        foreach($row as $v1 => $v2)
                            if(strpos($v1, $table_name . '.') === 0)
                                $tmp[$v1] = $v2;
                        $cols = array_merge($cols, $tmp);
                    }

                    # Select all columns.
                    else {
                        $cols = array_merge($cols, $row);
                    }

                    continue;
                }

                # Convert short column name to full column name (with table prefix) if possible.
                # The already-full column name is kept as is.
                $cols[$this->getColumnName($struct)] = $this->getColumnValue($struct, $row);

            }
            $new[] = $cols;
        }

        # Determine the uniqueness of all columns
        # so that we can safely remove column prefix without column name conflict.
        $this->constructColumnNameMapping(reset($new));

        $this->table = $new;
    }

    private function swap(&$x, &$y) {
        $tmp = $x;
        $x = $y;
        $y = $tmp;
    }

    # Note: $to is inclusive
    private function recursiveApplyOneOrder($struct, $order_index, $from, $to) {

        if($order_index >= count($struct)) return;

        # Sort rows in range $from - $to.
        # Bubble sort algorithm.
        $sort_by = $struct[$order_index]['base_expr'];
        $sort_direction = $struct[$order_index]['direction'];
        for($a=$from;$a<$to;$a++) {
            for($b=$a + 1;$b<=$to;$b++) {
                $x = $this->table[$a][$sort_by];
                $y = $this->table[$b][$sort_by];
                if($sort_direction === 'DESC') {
                    if($x < $y) $this->swap($this->table[$a], $this->table[$b]);
                } else {
                    if($x > $y) $this->swap($this->table[$a], $this->table[$b]);
                }
            }
        }

        if(count($struct) === $order_index + 1) return;

        # Sort next ? Determine the ranges for sorting,
        # it must maintain the order of the already-sorted fields.
        # Only sort next field when it's in the same group
        # i.e. it's in a group of the same value of this current field.
        $start = $from;
        $finish = $from;
        $prev = $this->table[$from][$sort_by];
        for($a=$from + 1;$a<=$to;$a++) {

            $value = $this->table[$a][$sort_by];

            if($prev === $value) {
                $finish = $a;
            }

            if($prev !== $value) {

                # Should sort here ?
                if($finish > $start) {
                    $this->recursiveApplyOneOrder($struct, $order_index + 1, $start, $finish);
                }

                $start = $a;
                $finish = $a;
                $prev = $value;
            }
        }

        # Should sort here ?
        if($finish > $start) {
            $this->recursiveApplyOneOrder($struct, $order_index + 1, $start, $finish);
        }
    }

    # Order
    private function applyOrder($struct) {

        if(count($struct) >= 2) {
            $this->recursiveApplyOneOrder($struct,0, 0, count($this->table) - 1);
            return;
        }

        if(count($this->table) === 0) return;

        $first = reset($struct);

        $sort_by = $this->toColumnNameWithPrefix($first['base_expr']);
        $sort_direction = $first['direction'];

        uasort($this->table, function($x, $y) use($sort_by, $sort_direction){
            if($sort_direction === 'DESC') return $x[$sort_by] < $y[$sort_by];
            return $x[$sort_by] > $y[$sort_by];
        });
    }

    # Limit.
    private function applyLimit($struct) {
        $this->table = array_slice($this->table, intval($struct['offset']), intval($struct['rowcount']));
    }

    private function recursiveApplyOneGroupBy($struct, $rows, $index = 0) {
        if($index >= count($struct)) return $rows;

        if(count($rows) === 0) return $rows;

        $new = [];
        foreach($rows as $row) {
            $value = $this->getColumnValue($struct[$index],$row);
            if(!array_key_exists($value,$new)) $new[$value] = [];
            $new[$value][] = $row;
        }

        $column_name = $this->toColumnNameWithPrefix($struct[$index]['base_expr']);

        $result = [];
        foreach($new as $key => $value) {
            $result[] = [
                $column_name => $key,
                'children' => $value,
            ];
        }

        if($index + 1 < count($struct))
            foreach($result as $key => $value) {
                $result[$key]['children'] = $this->recursiveApplyOneGroupBy($struct, $result[$key]['children'], $index + 1);
            }

        return $result;
    }

    private function recursiveFlattenGroupBy($rows) {

        $new = [];

        foreach($rows as $key => $row) {

            if(!array_key_exists('children',$row)) break;

            $children = $row['children'];
            unset($row['children']);
            $child_rows = $this->recursiveFlattenGroupBy($children);

            # Have column to merge ?
            if(count($child_rows) > 0)
                foreach($child_rows as $child_row) $new[] = array_merge($row, $child_row);

            # No column to merge.
            else {
                $row['children'] = $children;
                $new[] = $row;
            }
        }

        return $new;
    }

    # GROUP BY
    private function applyGroupBy($struct) {
        $new = $this->recursiveApplyOneGroupBy($struct, $this->table, 0);
        $new = $this->recursiveFlattenGroupBy($new);
        $this->table = $new;
    }

    # HAVING
    private function applyHaving($struct) {
        $this->applyWhere($struct);
    }

    private function getColumnsForSelect($select) {

        if(empty($select)) return;

        foreach($select as $struct)
            if(!empty($struct['alias']))
                $this->column_alias[$struct['alias']['name']] = $struct;
    }

    private static $inserts = [];
    public static function defineInsertIntoTable($table, $callback) {
        self::$inserts[$table] = $callback;
    }

    private static $updates = [];
    public static function defineUpdateTable($table, $callback) {
        self::$updates[$table] = $callback;
    }

    private static $deletes = [];
    public static function defineDeleteFromTable($table, $callback) {
        self::$deletes[$table] = $callback;
    }

    private function handleDelete($parsed) {
        $table = $parsed['FROM'][0]['table'];

        if(!array_key_exists($table,self::$deletes))
            throw new \Exception("DELETE FROM $table hasn't been defined yet");

        $where = '';
        $pos = stripos($this->sql, ' where ');
        if($pos !== false) $where = 'where ' . substr($this->sql, $pos + 6);

        $rows = SuperSql::execute("SELECT * FROM $table $where");
        foreach($rows as $key => $value) {
            $func = self::$deletes[$table];
            $func($value);
        }

        return count($this->table);
    }

    private function handleInsert($parsed) {

        $struct = $parsed['INSERT'];

        $table = $struct[1]['table'];

        if(!array_key_exists($table,self::$inserts))
            throw new \Exception("Callback for INSERT INTO $table hasn't been defined yet");

        # INSERT INTO ... VALUES ...
        if(array_key_exists('VALUES',$parsed)) {
            if($struct[2]['expr_type'] != 'column-list')
                throw new \Exception('Unsupported INSERT INTO');
            $columns = explode(',', trim($struct[2]['base_expr'], "()"));
            $values = explode(',', trim($parsed['VALUES'][0]['base_expr'], "()"));

            if(count($columns) != count($values))
                throw new \Exception("Column list length doesn't match with value list length");

            $row = [];
            foreach($columns as $key => $col) $row[trim($col)] = trim(trim($values[$key]), "'");

            $func = self::$inserts[$table];
            $func($this->removeColumnPrefixForRow($row));
            return 1;
        }

        # INSERT INTO ... SELECT ...

        unset($parsed['INSERT']);

        $rows = $this->handleSelect($parsed);

        if(empty($rows)) return;

        $func = self::$inserts[$table];
        foreach($rows as $row) $func($this->removeColumnPrefixForRow($row));

        return count($rows);
    }

    private function handleSelect($parsed) {

        $this->parsed = $parsed;

        $this->getColumnsForSelect($this->parsed['SELECT']);

        # FROM
        if(!empty($this->parsed['FROM']))
            $this->applyFrom($this->parsed['FROM']);
        else {
//			print 'Warning: SELECT without FROM' . PHP_EOL;
        }

        # WHERE
        if(!empty($this->parsed['WHERE']))
            $this->applyWhere($this->parsed['WHERE']);

        # GROUP BY
        if(!empty($this->parsed['GROUP']))
            $this->applyGroupBy($this->parsed['GROUP']);

        # HAVING
        if(!empty($this->parsed['HAVING']))
            $this->applyHaving($this->parsed['HAVING']);

        # ORDER
        if(!empty($this->parsed['ORDER']))
            $this->applyOrder($this->parsed['ORDER']);


        # LIMIT
        if(!empty($this->parsed['LIMIT']))
            $this->applyLimit($this->parsed['LIMIT']);

        # SELECT
        $this->applySelect($this->parsed['SELECT']);

        $this->total = count($this->table);

        return $this->table;
    }

    private static $truncates = [];
    public static function defineTruncateTable($table, $callback) {
        self::$truncates[$table] = $callback;
    }
    private function handleTruncate($struct) {
        $table = $struct[1];
        if(!array_key_exists($table,self::$truncates))
            throw new \Exception("Callback for TRUNCATE TABLE $table hasn't been defined yet");
        return self::$truncates[$table]();
    }

    private function handleUpdate($struct) {
        $table = $struct['UPDATE'][0]['table'];

        if(!array_key_exists($table,self::$updates))
            throw new \Exception("UPDATE $table hasn't been defined yet");

        $where = '';
        $pos = stripos($this->sql, ' where ');
        if($pos !== false) $where = 'where ' . substr($this->sql, $pos + 6);

        $this->_execute("SELECT * FROM $table $where");

        $func = self::$updates[$table];
        foreach($this->table as $row) {
            $updates = [];
            foreach($struct['SET'] as $update_struct) {
                $sub_tree = $update_struct['sub_tree'];
                $left = $sub_tree[0]['base_expr'];
                $right = $this->evaluateExpression(array_slice($sub_tree, 2), $row);
                $updates[$left] = $right;
            }
            $func($updates, $this->removeColumnPrefixForRow($row));
        }

        return count($this->table);
    }

    private $sql = '';
    private function _execute($sql) {
        $model = new PHPSQLParser($sql);

        $sql = str_replace('\n', ' ', $sql);
        $sql = str_replace('\r', ' ', $sql);
        $sql = str_replace('\t', ' ', $sql);

        $this->sql = $sql;

        # SHOW TABLES
        if(!empty($model->parsed['SHOW'])) {
            if($model->parsed['SHOW'][0]['base_expr'] === 'tables') {

                $result = [];
                foreach(self::$more_tables as $key => $callback)
                    $result[] = ['Tables_in_database' => $key];
                asort($result);
                print self::getFormattedOutput($result);
                return;

            } else {
                throw new \Exception('Unsupported SHOW query');
            }
        }

        # INSERT
        if(!empty($model->parsed['INSERT'])) {
            return $this->handleInsert($model->parsed);
        }

        # UDPATE
        if(!empty($model->parsed['UPDATE'])) {
            return $this->handleUpdate($model->parsed);
        }

        # DELETE
        if(!empty($model->parsed['DELETE'])) {
            return $this->handleDelete($model->parsed);
        }

        # TRUNCATE
        if(!empty($model->parsed['TRUNCATE'])) {
            return $this->handleTruncate($model->parsed['TRUNCATE']);
        }

        # SELECT
        return $this->handleSelect($model->parsed);
    }

    public static function execute($sql, ...$params) {

        if(count($params) > 0) $sql = vsprintf($sql,$params);

        $instance = new static();
        $result = $instance->_execute(strtolower($sql));

        # if SELECT statement, return the result rows.
        if(is_array($result)) {
            $instance->removeColumnPrefixIfPossible($result);
            return $result;
        }

        # if INSERT/UPDATE/DELETE statement, return the number of affected rows.
        return $result;
    }

    public static function printRows($rows) {
        $text = self::getFormattedOutput($rows);
        print str_replace(PHP_EOL, PHP_EOL . '   ',$text);
    }

    public static function mbStrPad($str, $pad_len, $pad_str = ' ', $dir = STR_PAD_RIGHT, $encoding = NULL)
    {
        $encoding = $encoding === NULL ? mb_internal_encoding() : $encoding;
        $padBefore = $dir === STR_PAD_BOTH || $dir === STR_PAD_LEFT;
        $padAfter = $dir === STR_PAD_BOTH || $dir === STR_PAD_RIGHT;
        $pad_len -= mb_strlen($str, $encoding);
        $targetLen = $padBefore && $padAfter ? $pad_len / 2 : $pad_len;
        $strToRepeatLen = mb_strlen($pad_str, $encoding);
        $repeatTimes = ceil($targetLen / $strToRepeatLen);
        $repeatedString = str_repeat($pad_str, max(0, $repeatTimes)); // safe if used with valid unicode sequences (any charset)
        $before = $padBefore ? mb_substr($repeatedString, 0, (int)floor($targetLen), $encoding) : '';
        $after = $padAfter ? mb_substr($repeatedString, 0, (int)ceil($targetLen), $encoding) : '';
        return $before . $str . $after;
    }

    public static function getFormattedOutput($rows) {

        $text = '';

        if(empty($rows) || count($rows) === 0) {
            $text .= 'Empty result' . PHP_EOL;
            return $text;
        }

        $text .= PHP_EOL;

        # Use max length of values.
        $length = [];

        if(!is_array($rows[0])) return 'Empty result';

        foreach($rows[0] as $col_name => $value) $length[$col_name] = strlen($col_name);
        foreach($rows as $row) {
            foreach($row as $col_name => $value) {
                if(!array_key_exists($col_name,$length)) $length[$col_name] = 0;
                if($length[$col_name] < strlen($value))
                    $length[$col_name] = strlen($value);
            }
        }

        foreach($length as $key => $value)
            if($value > 100) $length[$key] = 100;

        # Column header title.
        $row = reset($rows);
        foreach($row as $col_name => $value) {
            $padding = $length[$col_name];
            $text .= self::mbStrPad($col_name, $padding) . ' | ';
        }

        # Separation between column header and row data.
        $text .= PHP_EOL;
        foreach($row as $col_name => $value) {
            $padding = $length[$col_name];
            $text .= self::mbStrPad('', $padding, '-', STR_PAD_BOTH) . '---';
        }

        # Row data.
        $text .= PHP_EOL;
        foreach($rows as $row) {
            foreach($row as $col_name => $value) {

                $padding = $length[$col_name];

                # Text too long ?
                if(strlen($value) > $padding) {
                    $value = substr($value, 0, $padding - 2) . '..';
                }

                $line = self::mbStrPad(str_replace("â€™","'",$value), $padding, ' ') . ' | ';
                $text .= str_replace(PHP_EOL, ' ', $line);
            }
            $text .= PHP_EOL;
        }
        $text .=  PHP_EOL;
        $text .= 'Total: ' . count($rows) . ' rows ' . PHP_EOL;
        $text .= PHP_EOL;

        return $text;
    }
}
