<?php
/*
Plugin Name: AI Content Restrictor with User-Defined Sources and Tag Exclusion
Description: Crawls external sources, classifies content, and restricts publication based on admin criteria, user-defined sources, and excluded tags.
Version: 1.3
Author: Kim Jacobsen
*/

// Hook to initialize the plugin and add menu
add_action('admin_menu', 'ai_content_restrictor_menu');
function ai_content_restrictor_menu() {
    add_menu_page('AI Content Restrictor Settings', 'AI Content Restrictor', 'manage_options', 'ai-content-restrictor', 'ai_content_restrictor_settings_page');
}

// Settings Page in Admin Panel
function ai_content_restrictor_settings_page() {
    ?>
    <div class="wrap">
        <h1>AI Content Restrictor Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ai_content_restrictor_settings');
            do_settings_sections('ai-content-restrictor');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Register settings
add_action('admin_init', 'ai_content_restrictor_settings');
function ai_content_restrictor_settings() {
    // Register settings for restricted categories, user-defined sources, and excluded tags
    register_setting('ai_content_restrictor_settings', 'restricted_categories');
    register_setting('ai_content_restrictor_settings', 'user_defined_sources');
    register_setting('ai_content_restrictor_settings', 'excluded_tags');

    // Add sections and fields
    add_settings_section('ai_content_restrictor_main', 'Main Settings', null, 'ai-content-restrictor');
    add_settings_field('restricted_categories', 'Restricted Categories', 'restricted_categories_callback', 'ai-content-restrictor', 'ai_content_restrictor_main');
    add_settings_field('user_defined_sources', 'User-Defined Sources', 'user_defined_sources_callback', 'ai-content-restrictor', 'ai_content_restrictor_main');
    add_settings_field('excluded_tags', 'Excluded Tags', 'excluded_tags_callback', 'ai-content_restrictor', 'ai_content_restrictor_main');
}

// Settings field callback for restricted categories
function restricted_categories_callback() {
    $options = get_option('restricted_categories');
    echo "<input type='text' name='restricted_categories' value='$options' />";
}

// Settings field callback for user-defined sources (multiple URLs as a list)
function user_defined_sources_callback() {
    $sources = get_option('user_defined_sources');
    $sources_list = explode(PHP_EOL, $sources); // Convert to array
    echo "<ul id='source-list'>";
    foreach ($sources_list as $source_url) {
        echo "<li><input type='text' name='user_defined_sources[]' value='".esc_attr(trim($source_url))."' /> <button class='remove-source'>Remove</button></li>";
    }
    echo "</ul>";
    echo "<button id='add-source'>Add New Source</button>";
    echo "<script type='text/javascript'>
        document.getElementById('add-source').addEventListener('click', function() {
            var list = document.getElementById('source-list');
            var newItem = document.createElement('li');
            newItem.innerHTML = \"<input type='text' name='user_defined_sources[]' /> <button class='remove-source'>Remove</button>\";
            list.appendChild(newItem);
        });
    </script>";
}

// Settings field callback for excluded tags (tags as a comma-separated list)
function excluded_tags_callback() {
    $tags = get_option('excluded_tags');
    echo "<input type='text' name='excluded_tags' value='$tags' />";
    echo "<p>Enter the tags to exclude, separated by commas (e.g., 'tag1, tag2, tag3').</p>";
}

// Save the list of sources as a single string
add_action('update_option_user_defined_sources', 'save_user_defined_sources', 10, 2);
function save_user_defined_sources($old_value, $value) {
    if (is_array($value)) {
        $value = implode(PHP_EOL, array_map('trim', $value));
    }
    update_option('user_defined_sources', $value);
}

// Function to Crawl Content from User-Defined Sources
function crawl_user_defined_sources() {
    $sources = get_option('user_defined_sources');
    $sources_array = explode(PHP_EOL, $sources); // Split by new lines

    foreach ($sources_array as $source_url) {
        $source_url = trim($source_url);
        if (!empty($source_url)) {
            crawl_external_content($source_url); // Call existing function to crawl content
        }
    }
}

// Modify the Crawl External Content Function to Include AI Classification and Tag Checking
function crawl_external_content($source_url) {
    $response = wp_remote_get($source_url);
    if (is_wp_error($response)) {
        return;
    }
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true); // Assuming the content is in JSON format

    // Example logic for crawling and restricting content
    foreach ($data['articles'] as $article) {
        $category = classify_content($article['text']); // AI classification
        $tags = $article['tags']; // Assuming tags are provided in the crawled content

        // Get restricted categories and excluded tags
        $restricted_categories = explode(',', get_option('restricted_categories'));
        $excluded_tags = explode(',', get_option('excluded_tags'));

        // Check if the article contains any excluded tags
        if (!check_for_excluded_tags($tags, $excluded_tags) && !in_array(trim($category), $restricted_categories)) {
            publish_article($article); // Publish the article if not restricted
        }
    }
}

// Function to Check for Excluded Tags
function check_for_excluded_tags($article_tags, $excluded_tags) {
    foreach ($excluded_tags as $excluded_tag) {
        $excluded_tag = trim($excluded_tag);
        if (in_array($excluded_tag, $article_tags)) {
            return true; // Found an excluded tag, block the article
        }
    }
    return false; // No excluded tags found
}

// Example AI Classification Function (Modify with a real AI API)
function classify_content($content) {
    $api_url = 'https://mock-ai-api.com/classify'; // Replace with a real AI API URL
    $response = wp_remote_post($api_url, array(
        'body' => json_encode(array('text' => $content)),
        'headers' => array('Content-Type' => 'application/json')
    ));

    if (is_wp_error($response)) {
        return 'unknown'; // Default if AI fails
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    return $data['category']; // Return the category classified by the AI
}

// Function to Publish Article
function publish_article($article) {
    $post_data = array(
        'post_title'   => wp_strip_all_tags($article['title']),
        'post_content' => $article['content'],
        'post_status'  => 'publish',
        'post_author'  => 1,
        'tags_input'   => $article['tags'], // Add the tags to the post
    );
    wp_insert_post($post_data);
}

// Schedule the crawling process
add_action('wp', 'setup_crawl_schedule');
function setup_crawl_schedule() {
    if (!wp_next_scheduled('crawl_user_defined_sources_event')) {
        wp_schedule_event(time(), 'hourly', 'crawl_user_defined_sources_event');
    }
}

add_action('crawl_user_defined_sources_event', 'crawl_user_defined_sources');
