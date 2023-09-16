<?php

namespace HitchinHackspace\SlackViewer;

function sort_channels($channels) {
    $channels = iterator_to_array($channels);

    usort($channels, function($a, $b) { return $a->getName() <=> $b->getName(); });

    return $channels;
}

function render_channel_list($channels, $name, $classes = []) {
    if (!is_array($classes))
        $classes = [$classes];

    $classes = implode(' ', $classes);

    $channels = sort_channels($channels);
    ?>
    <li class="<?= $classes ?>">
        <h3 class="name"><?= esc_attr($name) ?></h3>
        <ul class="channel-list">
            <?php foreach ($channels as $channel) { ?>
                <li class="channel">
                    <a href="?path=<?= esc_attr($channel->getName()) ?>">
                        <span class="channel-logo"></span>
                        <span class="channel-info">
                            <span class="name"><?= htmlspecialchars($channel->getName()) ?></span>
                            <span class="purpose"><?= htmlspecialchars($channel->getPurpose()) ?></span>
                            <span class="current-topic"><?= htmlspecialchars($channel->getTopic()) ?></span>
                        </span>
                    </a>
                </li>
            <?php } ?>
        </ul>
    </li>
    <?php
}

?>

<h2><a href="?path=">Slack Archives</a> > All Channels</h2>
<ul class="channel-groups">
    <?php render_channel_list($archive->getGeneralChannels(), 'General', 'general'); ?>
    <?php render_channel_list($archive->getStandardChannels(), 'Other Channels', 'other'); ?>
    <?php render_channel_list($archive->getArchivedChannels(), 'Archived Channels', 'archived'); ?>
</ul>