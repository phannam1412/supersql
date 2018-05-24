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

try {
    if(count($argv) >= 2)
        $rows = SuperSql::execute($argv[1]);
    else
        $rows = SuperSql::execute("SELECT user_email, post_title FROM pending_post p inner join user u on p.post_author = u.ID");
    SuperSql::printRows($rows);
} catch(Exception $e) {
    print $e->getMessage() . PHP_EOL;
}
