<?php

include '../core.php';
include '../vendor/autoload.php';

use SuperSql\SuperSql;

$wp_did_header = true;

# Load the WordPress library.
require_once( dirname(__FILE__) . '/wp-load.php' );

# Set up the WordPress query.
wp();

# users
SuperSql::defineSelectFromTable("users", function() {
    $users = get_users();
    $users = array_map(function($item){
        return $item->data;
    }, $users);
    $data = json_decode(json_encode($users), TRUE);
    return $data;
});

# pending_posts
SuperSql::defineSelectFromTable("pending_posts", function() {
    $posts = get_posts(['numberposts' => 1000, 'post_status' => 'pending']);
    $data = json_decode(json_encode($posts), TRUE);
    return $data;
});

# posts
SuperSql::defineSelectFromTable("posts", function() {
    $posts = get_posts(['numberposts' => 1000]);
    $data = json_decode(json_encode($posts), TRUE);
    return $data;
});

# taxonomies
SuperSql::defineSelectFromTable("taxonomies", function() {
    $data = get_taxonomies();
    $result = [];
    foreach($data as $key => $value) {
        $result[] = ['name' => $value];
    }
    return $result;
});

# terms
SuperSql::defineSelectFromTable("terms", function() {
    $data = get_terms();
    return json_decode(json_encode($data), true);
});

# term_taxonomy
SuperSql::defineSelectFromTable("term_taxonomy", function() {
    global $wpdb;
    $data = $wpdb->get_results("SELECT * FROM wp_term_taxonomy");
    return json_decode(json_encode($data), true);
});

# term_relationships
SuperSql::defineSelectFromTable("term_relationships", function() {
    global $wpdb;
    $data = $wpdb->get_results("SELECT * FROM wp_term_relationships");
    return json_decode(json_encode($data), true);
});

$cache = false;

# products
SuperSql::defineSelectFromTable("products", function() {
    global $cache;
    if($cache) return $cache;
    $products = wc_get_products(['numberposts' => 1000]);
    $result = [];
    foreach($products as $p) $result[] = $p->get_data();
    $cache = $result;
    return $result;
});

# product_categories
SuperSql::defineSelectFromTable("product_categories", function() {
    $data = get_terms(['taxonomy' => 'product_cat']);
    return json_decode(json_encode($data), true);
});

# product_category_relation
SuperSql::defineSelectFromTable("product_category_relation", function() {
    $products = SuperSql::execute("select * from products");
    $result = [];
    foreach($products as $p) {
        $cats = json_decode($p['category_ids'], true);
        foreach($cats as $c) {
            $result[] = [
                'category' => $c,
                'product' => $p['id'],
            ];
        }
    }
    return $result;
});

//$rows = SuperSql::execute("
//select * from products
//");
//SuperSql::printRows($rows);exit;

$tests = [];

$tests[] = [

    # expected
    function() {
        $result = [];
        $products = wc_get_products(['numberposts' => 1000]);
        $mapping = [];
        foreach($products as $p) {
            $categories = get_the_terms($p->id, 'product_cat');
            foreach($categories as $c) {
                if(!isset($mapping[$c->name]))
                    $mapping[$c->name] = 0;
                $mapping[$c->name]++;
            }
        }
        foreach($mapping as $key => $value)
            $result[] = [
                'category' => $key,
                'product_count' => $value,
            ];
        return $result;
    },

    # actual
    "select pc.name AS category, count(*) AS product_count from products p
    inner join product_category_relation pcr on p.id = pcr.product
    inner join product_categories pc on pcr.category = pc.term_id
    group by pc.name
    "
];

$tests[] = [

    # expected
    function() {
        $posts = get_posts(['numberposts' => 10]);
        return json_decode(json_encode($posts), TRUE);
    },

    # actual
    "SELECT * FROM posts LIMIT 10"
];

$tests[] = [

    # expected
    function() {
        $posts = get_posts(['numberposts' => 1000]);
        $result = [];
        foreach($posts as $post) {
            $author = get_user_by('id' , $post->post_author);
            $result[] = [
                'user_email' => $author->user_email,
                'post_title' => $post->post_title,
            ];
        }
        return $result;
    },

    # actual
    "SELECT user_email,post_title FROM posts p inner join users u on p.post_author = u.ID"
];

# List users together with their posts.
$tests[] = [

    # expected
    function () {
        $posts = get_posts(['numberposts' => 1000]);
        $result = [];
        foreach($posts as $post) {
            $user = get_user_by('id', $post->post_author);
            $result[] = [
                'user' => $user->user_email,
                'post' => $post->post_title,
            ];
        }
        return $result;
    },

    # actual
    "SELECT user_email AS user, post_title AS post FROM posts p INNER JOIN users u ON p.post_author = u.ID"
];

# Every user has many posts ?
$tests[] = [

    # expected
    function() {
        $users = get_users();
        $result = [];
        foreach($users as $u) {
            $posts = get_posts(['author' => $u->ID]);
            if(count($posts) === 0) continue;
            $result[] = [
                'user' => $u->user_email,
                'post_count' => count($posts),
            ];
        }
        return $result;
    },

    # actual
    "SELECT u.user_email AS user, count(*) AS post_count FROM users u INNER JOIN posts p ON u.ID = p.post_author GROUP BY user_email"
];


foreach($tests as $key => $test) {

    $expected_from = microtime(true);
    $expected = $test[0]();
    $expected_time = microtime(true) - $expected_from;
    $expected_time = round($expected_time * 1000);

    $actual_from = microtime(true);
    $actual = SuperSql::execute($test[1]);
    $actual_time = microtime(true) - $actual_from;
    $actual_time = round($actual_time * 1000);

    if(count($expected) !== count($actual)) {
        print " FAILED test $key: Number of returned rows are different, expected count: " . count($expected) . ', actual count: ' . count($actual) . PHP_EOL;
        exit;
    }
    foreach($expected as $key1 => $row) {
        foreach($row as $key2 => $col) {
            if($col != $actual[$key1][strtolower($key2)]) {
                print " FAILED test $key: Different values at row $key1, expected: $col, actual: " . $actual[$key1][$key2];
                exit;
            }
        }
    }

    $diff = $expected_time / $actual_time;
    $status = $diff < 0.9 ? ', bad performance' : '';
    $status = $diff < 0.7 ? ', very bad performance' : $status;
    $status = $diff < 0.5 ? ', worst performance' : $status;

    print " PASSED test $key: wordpress $expected_time ms, supersql $actual_time ms" . $status . PHP_EOL;
}
print ' PASSED all tests';