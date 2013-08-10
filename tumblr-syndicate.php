<?php

/*
Plugin Name: Syndicate from Tumblr
Plugin URI: http://trevorwilsonphoto.com
Description: Imports photosets from my tumblr as photo posts.
Author: Trevor Wilson
Version: 1.0
Author URI: http://trevorwilsonphoto.com
*/

define('DOWNLOAD_IMAGES', true);
define('SAVE_POSTS', true);
define('FEED_URL', "http://trevorwilson.tumblr.com/api/read?tagged=photoset"); // TODO: Make configurable

add_action('tumblrimportupdatefeed', 'tumblr_import_update_feed');
register_activation_hook(__FILE__, 'tumblr_import_setup_cron');
register_deactivation_hook(__FILE__, 'tumblr_import_shutdown_cron');

function tumblr_import_setup_cron() {
	$result = wp_schedule_event(time(), 'twicedaily', 'tumblrimportupdatefeed'); // TODO: Make configurable
	if ( $result === null) {
		error_log('[tumblr import] Scheduled import cron job');	
	} else {
		error_log('[tumblr import] Failed to schedule cron job');
	}
}

function tumblr_import_shutdown_cron() {
	$timestamp = wp_next_scheduled('tumblrimportupdatefeed');
	WPUnscheduleEventsByName('tumblrimportupdatefeed');
}

function tumblr_import_update_feed() {
	require ( ABSPATH . 'wp-admin/includes/image.php' );
	$feed_xml = simplexml_load_file(FEED_URL);
	$posts = $feed_xml->posts;

	error_log("[tumblr-import] Starting up");

	if ( count($posts->post) ) {
		foreach ( $posts->post as $post ) {
			tumblr_import_save_wp_post($post);
		}
	}
}

function tumblr_import_save_wp_post($tumblr_post) {

	$attributes = $tumblr_post->attributes();
	$upload_dir = wp_upload_dir();

		
	$post['post_name'] = (string) $attributes['slug'];
	$post['post_content'] = (string) $tumblr_post->{'photo-caption'} . "\n";
	$post['post_title'] = get_title($post['post_content']);
	$post['post_date'] = str_replace(' GMT', '', (string) $attributes['date-gmt']);
	$post['post_status'] = 'publish';
	$post['post_author'] = 1; // TODO: Make configurable
	$post['post_category'] = array('16'); // TODO: Make configurable
	$post['tags_input'] = (array) $tumblr_post->tag;
	$tumblr_id = (string) $attributes['id'];

	$existing_posts = get_posts(array( 'meta_key' => 'tumblr_id', 'meta_value' => $tumblr_id ));

	$featured_image = null;

	$photo_urls = array();
	
	foreach ( $tumblr_post->photoset->photo as $photo ) {
		
		$url = (string) $photo->{'photo-url'}[0];
		$components = explode('/', $url);
		$filename = $components[count($components) - 1];
		$web_url = $upload_dir['path'] . '/' . $filename;
		if ( DOWNLOAD_IMAGES ) {
			file_put_contents($web_url, file_get_contents($url));
		}
		$local_url = content_url($filename);
		if ( empty($featured_image) ) {
			$featured_image = array('local' => $local_url, 'url' => $web_url, 'filename' => $filename);
		}
	
		
		array_push($photo_urls, $local_url);
		$post['post_content'] .= '<p><img src="' . $url . '"/></p>' . "\n";
	}

	if ( SAVE_POSTS && empty($existing_posts) ) {
		$post_id = wp_insert_post($post);
		if ( $post_id ) {
			add_post_meta($post_id, 'tumblr_id', $tumblr_id, true);
			$wp_filetype = wp_check_filetype($featured_image['local']);

			// TODO: Do this for all images
			$featured_image_post = array( 
					'post_title' => $featured_image['filename'],
					'post_content' => '',
					'post_status' => 'inherit',
					'post_mime_type' => $wp_filetype['type']
				);

			$attachment_id = wp_insert_attachment($featured_image_post, $featured_image['url'], $post_id);
			if ( $attachment_id ) {
				set_post_thumbnail($post_id, $attachment_id);
			$metadata = wp_generate_attachment_metadata($attachment_id, get_attached_file($attachment_id));
				wp_update_attachment_metadata($attachment_id, $metadata);
			}
		}
	}

}

function get_title($body) {
	$body_text = strip_tags($body);
	$max_length = 60;
	$title = "";
	if ( strlen($body_text) > $max_length ) {
		if ( preg_match("/^((.*?)[.?!])\s/", $body_text, $matches) ) {
			$title = $matches[1];
		} else { 
			$title = substr($body_text, 0, $max_length) . "...";
		}
	} else {
		$title = $body_text;
	}
	return $title;
}

function WPUnscheduleEventsByName($strEventName) {

	// this function removes registered WP Cron events by a specified event name.

	$arrCronEvents = _get_cron_array();
	foreach ($arrCronEvents as $nTimeStamp => $arrEvent)
		if (isset($arrCronEvents[$nTimeStamp][$strEventName])) unset( $arrCronEvents[$nTimeStamp] );
	_set_cron_array( $arrCronEvents );
}


