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

SuperSql::defineSelectFromTable("posts", function() {
    $posts = get_posts();
    $data = json_decode(json_encode($posts), TRUE);
    return $data;
});

$rows = SuperSql::execute("SELECT * FROM posts");
SuperSql::printRows($rows);