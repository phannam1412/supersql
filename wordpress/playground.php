<?php

include '../core.php';
include '../vendor/autoload.php';

use SuperSql\SuperSql;

$wp_did_header = true;

// Load the WordPress library.
require_once( dirname(__FILE__) . '/wp-load.php' );

// Set up the WordPress query.
wp();

SuperSql::defineSelectFromTable("user", function() {
    $users = get_users();
    $users = array_map(function($item){
        return $item->data;
    }, $users);
    $data = json_decode(json_encode($users), TRUE);
    return $data;
});

SuperSql::defineSelectFromTable("pending_post", function() {
    $posts = get_posts(['numberposts' => 1000, 'post_status' => 'pending']);
    $data = json_decode(json_encode($posts), TRUE);
    return $data;
});

SuperSql::defineSelectFromTable("post", function() {
    $posts = get_posts(['numberposts' => 1000]);
    $data = json_decode(json_encode($posts), TRUE);
    return $data;
});

$tests = [];

$tests[] = [
    function() { # expected
        $posts = get_posts();
        return json_decode(json_encode($posts), TRUE);
    },
    "SELECT * FROM post LIMIT 5" # actual
];

$tests[] = [
    function() { # expected
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
    "SELECT user_email,post_title FROM post p inner join user u on p.post_author = u.ID" # actual
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
        print " FAILED test $key: Number of returned rows are different, expected: " . count($expected) . ', actual: ' . count($actual) . PHP_EOL;
        exit;
    }
    foreach($expected as $key1 => $row) {
        foreach($row as $key2 => $col) {
            if($col != $actual[$key1][strtolower($key2)]) {
                print " FAILED test $key: Rows $key1 are different, expected: $col, actual: " . $actual[$key1][$key2];
                exit;
            }
        }
    }

    print " PASSED test $key: wordpress $expected_time ms, supersql $actual_time ms" . PHP_EOL;
}
print ' PASSED all tests';