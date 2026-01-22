<?php
if (!defined('ABSPATH')) exit;

// Get base URL
$ps_user = PeepSoUser::get_instance($viewed_user_id);
$base_url = trailingslashit($ps_user->get_profileurl());
?>

<div class="bcc-project-tabs">

    <div class="ps-tabs ps-tabs--sub">
        <?php foreach ($sections as $s): ?>
            <a class="ps-tab <?php echo $s === $section ? 'active' : ''; ?>"
               href="<?php echo esc_url("{$base_url}projects/{$s}/{$mode}"); ?>">
                <?php echo ucfirst($s); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="ps-tabs ps-tabs--secondary ps-spacing--sm">
        <?php foreach (['view', 'create'] as $m): ?>
            <?php if ($m === 'create' && !$can_edit) continue; ?>
            <a class="ps-tab <?php echo $m === $mode ? 'active' : ''; ?>"
               href="<?php echo esc_url("{$base_url}projects/{$section}/{$m}"); ?>">
                <?php echo ucfirst($m); ?>
            </a>
        <?php endforeach; ?>
    </div>

</div>
