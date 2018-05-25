<?php

include '../core.php';
include '../vendor/autoload.php';

use SuperSql\SuperSql;

$wp_did_header = true;

// Load the WordPress library.
require_once( dirname(__FILE__) . '/wp-load.php' );

// Set up the WordPress query.
wp();

SuperSql::defineSelectFromTable("users", function() {
    $users = get_users();
    $users = array_map(function($item){
        return $item->data;
    }, $users);
    $data = json_decode(json_encode($users), TRUE);
    return $data;
});

SuperSql::defineSelectFromTable("pending_posts", function() {
    $posts = get_posts(['numberposts' => 1000, 'post_status' => 'pending']);
    $data = json_decode(json_encode($posts), TRUE);
    return $data;
});

SuperSql::defineSelectFromTable("posts", function() {
    $posts = get_posts(['numberposts' => 1000]);
    $data = json_decode(json_encode($posts), TRUE);
    return $data;
});

SuperSql::defineSelectFromTable("taxonomies", function() {
    $data = get_taxonomies();
    $result = [];
    foreach($data as $key => $value) {
        $result[] = ['name' => $value];
    }
    return $result;
});

SuperSql::defineSelectFromTable("terms", function() {
    $data = get_terms();
    return json_decode(json_encode($data), true);
});

SuperSql::defineSelectFromTable("term_taxonomy", function() {
    global $wpdb;
    $data = $wpdb->get_results("SELECT * FROM wp_term_taxonomy");
    return json_decode(json_encode($data), true);
});

SuperSql::defineSelectFromTable("term_relationships", function() {
    global $wpdb;
    $data = $wpdb->get_results("SELECT * FROM wp_term_relationships");
    return json_decode(json_encode($data), true);
});

//$rows = SuperSql::execute(    "  SELECT * FROM posts p
//        INNER JOIN term_relationships tr ON p.ID = tr.object_id
//        INNER JOIN term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
//      "
//);
//SuperSql::printRows($rows);exit;

$tests = [];

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


//$tests[] = [
//
//    # expected
//    function() {
//        $posts = get_posts(['numberposts' => 1000]);
//        $mapping = [];
//        foreach($posts as $post) {
//            $terms = wp_get_post_terms($post->ID);
//            foreach($terms as $term) {
//                if(!isset($mapping[$term->taxonomy])) {
//                    $mapping[$term->taxonomy] = 0;
//                }
//                $mapping[$term->taxonomy]++;
//            }
//        }
//        $result = [];
//        foreach($mapping as $key => $value) $result[] = ['taxonomy' => $key, 'post_count' => $value];
//        return $result;
//    },
//
//    # actual
//    "  SELECT tt.taxonomy, count(*) AS post_count FROM posts p
//        INNER JOIN term_relationships tr ON p.ID = tr.object_id
//        INNER JOIN term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
//      "
//];

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