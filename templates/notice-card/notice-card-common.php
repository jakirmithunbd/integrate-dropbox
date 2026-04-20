<?php defined('ABSPATH') || exit;

if (! empty($args)) :
    // Safe defaults to avoid "undefined index" notices
    $ccpidb_title            = isset($args['title']) ? $args['title'] : '';
    $ccpidb_description      = isset($args['description']) ? $args['description'] : '';
    $ccpidb_icon             = isset($args['icon']) ? $args['icon'] : '';
    $ccpidb_wrapper_class    = isset($args['wrapper_class']) ? $args['wrapper_class'] : '';
    $ccpidb_iconClass        = isset($args['iconClass']) ? $args['iconClass'] : '';
    $ccpidb_card_status      = isset($args['card_status']) ? $args['card_status'] : 'primary';
    $ccpidb_primary_button   = isset($args['primary_button']) ? (array) $args['primary_button'] : [];
    $ccpidb_secondary_button = isset($args['secondary_button']) ? (array) $args['secondary_button'] : [];

    // Button field fallbacks
    $ccpidb_pb_title  = isset($ccpidb_primary_button['title']) ? $ccpidb_primary_button['title'] : '';
    $ccpidb_pb_url    = isset($ccpidb_primary_button['url']) ? $ccpidb_primary_button['url'] : '';
    $ccpidb_pb_target = ! empty($ccpidb_primary_button['target']) ? '_blank' : '_self';
    $ccpidb_pb_icon   = isset($ccpidb_primary_button['icon']) ? $ccpidb_primary_button['icon'] : 'check';
    $ccpidb_pb_class  = isset($ccpidb_primary_button['class']) ? ' ccpidb-' . $ccpidb_primary_button['class'] : '';
    $ccpidb_sb_title  = isset($ccpidb_secondary_button['title']) ? $ccpidb_secondary_button['title'] : '';
    $ccpidb_sb_url    = isset($ccpidb_secondary_button['url']) ? $ccpidb_secondary_button['url'] : '';
    $ccpidb_sb_target = ! empty($ccpidb_secondary_button['target']) ? '_blank' : '_self';
    $ccpidb_sb_icon   = isset($ccpidb_secondary_button['icon']) ? $ccpidb_secondary_button['icon'] : 'check';
    $ccpidb_sb_class  = isset($ccpidb_secondary_button['class']) ? ' ccpidb-' . $ccpidb_secondary_button['class'] : '';
    ?>
    <div class="ccpidb-top-level-wrapper <?php echo esc_attr($ccpidb_wrapper_class)?>">
        <div class="ccpidb-notice-card ccpidb-notice-status-<?php echo esc_attr($ccpidb_card_status); ?>">
            <div class="ccpidb-notice-card-wrapper flex-center flex-col">
                <?php if (! empty($ccpidb_icon)) : ?>
                    <span class="ccpidb-icon ccpidb-notice-card-wrapper-icon <?php echo esc_attr($ccpidb_iconClass); ?>"><?php echo esc_html($ccpidb_icon); ?></span>
                <?php endif; ?>

                <div class="ccpidb-notice-card-wrapper-content flex-center flex-col">
                    <?php if (! empty($ccpidb_title)) : ?>
                        <h3 class="ccpidb-title"><?php echo esc_html($ccpidb_title); ?></h3>
                    <?php endif; ?>

                    <?php if (! empty($ccpidb_description)) : ?>
                        <p class="ccpidb-description"><?php echo esc_html($ccpidb_description); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($ccpidb_pb_title || $ccpidb_sb_title) : ?>
                    <div class="ccpidb-notice-card-wrapper-buttons flex-center gap--sm">
                        <?php if ($ccpidb_pb_title) : ?>
                        <?php if ($ccpidb_pb_url) : ?>
                            <a href="<?php echo esc_url($ccpidb_pb_url); ?>"
                            target="<?php echo esc_attr($ccpidb_pb_target); ?>"
                            class="ccpidb-notice-card-btn ccpidb-button ccpidb-button--primary ccpidb-button--medium ccpidb-text-sm ccpidb-font-normal ccpidb-rounded-sm ccpidb-btn--<?php echo esc_attr($ccpidb_card_status); ?> <?php echo esc_attr($ccpidb_pb_class); ?>">
                        <?php else : ?>
                            <button type="button"
                            class="ccpidb-notice-card-btn ccpidb-button ccpidb-button--primary ccpidb-button--medium ccpidb-text-sm ccpidb-font-normal ccpidb-rounded-sm ccpidb-btn--<?php echo esc_attr($ccpidb_card_status); ?> <?php echo esc_attr($ccpidb_pb_class); ?>">
                        <?php endif; ?>

                            <span class="ccpidb-icon text-md"><?php echo esc_html($ccpidb_pb_icon); ?></span>
                            <span><?php echo esc_html($ccpidb_pb_title); ?></span>
                        <?php if ($ccpidb_pb_url) : ?>
                            </a>
                        <?php else : ?>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>


                        <?php if ($ccpidb_sb_title) : ?>
                            <?php if ($ccpidb_sb_url) : ?>
                                <a href="<?php echo esc_url($ccpidb_sb_url); ?>"
                                target="<?php echo esc_attr($ccpidb_sb_target); ?>"
                                class="ccpidb-notice-card-btn ccpidb-btn ccpidb-btn--rounded-sm ccpidb-btn--<?php echo esc_attr($ccpidb_card_status); ?> <?php echo esc_attr($ccpidb_sb_class); ?>">
                            <?php else : ?>
                                <button type="button"
                                class="ccpidb-notice-card-btn ccpidb-btn ccpidb-btn--rounded-sm ccpidb-btn--<?php echo esc_attr($ccpidb_card_status); ?> <?php echo esc_attr($ccpidb_sb_class); ?>">
                            <?php endif; ?>

                                <span class="ccpidb-icon text-md"><?php echo esc_html($ccpidb_sb_icon); ?></span>
                                <span><?php echo esc_html($ccpidb_sb_title); ?></span>
                            <?php if ($ccpidb_sb_url) : ?>
                                </a>
                            <?php else : ?>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
<?php endif; ?>