<?php

namespace HitchinHackspace\SlackViewer;

$channels = $archive->getChannelList();

sort($channels);

?>

<h2><a href="?path=">Slack Archives</a> > All Channels</h2>
<ul class="channel-list">
    <?php foreach ($channels as $channel) { ?>
        <li>
            <a href="?path=<?= esc_attr($channel->getName()) ?>">#<?= htmlspecialchars($channel->getName()) ?></a>
        </li>
    <?php } ?>
</ul>