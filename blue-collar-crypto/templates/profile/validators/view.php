<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validators – Profile View
 *
 * Shows validators created by the viewed user.
 * Owners see all statuses, visitors see published only.
 */

// --------------------------------------------------
// User context (PeepSo profile)
// --------------------------------------------------
$viewed_user_id  = bcc_get_current_user_id();
$current_user_id = get_current_user_id();

if (!$viewed_user_id) {
    echo '<div class="ps-alert ps-alert--error"><p>User not found.</p></div>';
    return;
}

$is_owner = ((int) $viewed_user_id === (int) $current_user_id);

// --------------------------------------------------
// Fetch validators via repository
// --------------------------------------------------
$repo = new BCC_ValidatorRepository();

/**
 * IMPORTANT:
 * The repository must respect $is_owner internally
 * and include all post statuses for owners.
 */
$validators = $repo->for_user(
    $viewed_user_id,
    $is_owner,
    [
        'limit' => 50,
    ]
);

if (empty($validators)) {
    if ($is_owner) {
        echo '<div class="ps-alert ps-alert--info"><p>You haven’t created any validators yet.</p></div>';
    } else {
        echo '<div class="ps-alert ps-alert--info"><p>This user has no public validators yet.</p></div>';
    }
    return;
}

// --------------------------------------------------
// Render validators
// --------------------------------------------------
foreach ($validators as $validator) {

    if (!$validator instanceof BCC_Validator) {
        continue;
    }

    $post_id = (int) $validator->id();
    if (!$post_id) {
        continue;
    }

    // ----------------------------------------------
    // Build validator-specific content (networks)
    // ----------------------------------------------
    $chains = get_field('chains_you_validate_for', $post_id);

    ob_start();

    if (is_array($chains) && !empty($chains)) {

        echo '<ul class="bcc-validator-networks">';

        foreach ($chains as $row) {

            $network = $row['network'] ?? null;

            if (is_object($network) && !empty($network->post_title)) {

                $commission = isset($row['chain_commission']) && $row['chain_commission'] !== ''
                    ? number_format((float) $row['chain_commission'], 2) . '%'
                    : null;

                $uptime = isset($row['chain_uptime']) && $row['chain_uptime'] !== ''
                    ? number_format((float) $row['chain_uptime'], 2) . '%'
                    : null;

                echo '<li>';
                echo esc_html($network->post_title);

                if ($commission) {
                    echo ' · Commission: ' . esc_html($commission);
                }

                if ($uptime) {
                    echo ' · Uptime: ' . esc_html($uptime);
                }

                echo '</li>';
            }
        }

        echo '</ul>';

    } else {
        echo '<p>No networks added yet.</p>';
    }

    $content = ob_get_clean();

    // ----------------------------------------------
    // Render via shared card renderer
    // ----------------------------------------------
    bcc_render_peepso_card([
        'post_id' => $post_id,
        'meta'    => 'Validator · ' . esc_html(get_the_date('', $post_id)),
        'content' => $content,
        'actions' => bcc_get_post_actions($post_id, $is_owner),
    ]);
}
