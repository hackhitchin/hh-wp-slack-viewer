<?php

namespace HitchinHackspace\SlackViewer;

use Throwable;

// Fetch update information from GitHub, given a repository and a file within it containing metadata.
function get_github_file_data($repoURI, $filename, $keys) {
    // Get the location of machine-readable files.
    $rawURI = str_replace('https://github.com', 'https://raw.githubusercontent.com', $repoURI) . '/master';

    // ... specifically, the file with the data we need
    $infoURI = "$rawURI/$filename";

    try {
        return get_file_data($infoURI, $keys);
    }
    catch (Throwable $t) {
        return null;
    }
}

// Fetch automatic updates from github
add_filter('update_plugins_github.com', function($update, $plugin_data, $plugin_file, $locales) {
    $repoURI = $plugin_data['Plugin URI'];

    $githubInfo = get_github_file_data($repoURI, $plugin_file, [
        'id' => 'Update URI',
        'version' => 'Version',
        'url' => 'Plugin URI'
    ]);

    if (!$githubInfo)
        return $update;
 
    // Tell WordPress where to get the archive, if it wants.
    $update['package'] = "$repoURI/archive/refs/heads/master.zip";
    
    return $update;
 }, 10, 4);