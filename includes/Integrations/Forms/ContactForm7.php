<?php

namespace CodeConfig\IDB\Integrations\Forms;

use CodeConfig\IDB\Integrations\BaseIntegration;
use CodeConfig\IDB\Shortcode;
use CodeConfig\IDB\Utils\Singleton;

defined('ABSPATH') || exit('No direct script access allowed');

/**
 * Contact Form 7 Integration
 * Manages the integration with Contact Form 7 plugin
 */
class ContactForm7 extends BaseIntegration
{
    use Singleton;

    public function __construct()
    {
        parent::__construct('contactForm7', 'Contact Form 7');
    }

    /**
     * Initialize the integration
     */
    public function init(string $id, array $integration): void
    {
        add_action('wpcf7_init', [$this, 'wpcf7_init']);
        add_action('wpcf7_admin_init', [$this, 'add_tag_generator'], 99);

        add_filter('wpcf7_validate_dropbox', [$this, 'validate_field'], 10, 2);
        add_filter('wpcf7_validate_dropbox*', [$this, 'validate_field'], 10, 2);
    }

    public function wpcf7_init()
    {
        if (function_exists('wpcf7_add_form_tag')) {
            wpcf7_add_form_tag(['dropbox', 'dropbox*'], [$this, 'dropbox_tag'], true);
        }
    }

    public function dropbox_tag($tag)
    {
        $tag = new \WPCF7_FormTag($tag);

        if (empty($tag->name)) {
            return '';
        }

        $validation_error = wpcf7_get_validation_error($tag->name);
        $class            = wpcf7_form_controls_class($tag->type, 'upload-file-list ccpidb-hidden');

        if ($validation_error) {
            $class .= ' wpcf7-not-valid';
        }

        $data                   = ['id' => $tag->get_option('id', '', true) ?? ''];
        $data['isFormUploader'] = 'cf7';

        if ('*' === substr($tag->type, -1)) {
            $data['isRequired'] = true;
        }

        $atts = [
            'name'          => $tag->name,
            'type'          => 'hidden',
            'class'         => $class,
            'tabindex'      => $tag->get_option('tabindex', 'signed_int', true),
            'aria-invalid'  => $validation_error ? 'true' : 'false',
            'aria-required' => $tag->is_required() ? 'true' : 'false',
        ];

        $atts_str = wpcf7_format_atts($atts);
        $user_id  = esc_attr(get_current_user_id());
        $tag_name = esc_attr($tag->name);

        $shortcode     = Shortcode::getInstance()->render($data);

        if (empty($shortcode)) {
            return sprintf('<div class="wpcf7-not-valid-tip">%s</div>', __('[File Uploader]: Your provided shortcode id is not valid for CF7 File Uploader.', 'integrate-dropbox'));
        }

        $html = empty($data['id']) ? (
            sprintf('<div class="wpcf7-not-valid-tip">%s</div>', esc_html__('Please configure the uploader first', 'integrate-dropbox'))
        ) : sprintf(
            '<div class="wpcf7-form-control-wrap ccpidb-cf7-file-uploader" data-name="%s">
            %s
                <input %s />
                <input type="hidden" name="_user_id" value="%s" />
                %s
            </div>',
            $tag_name,
            $shortcode,
            $atts_str,
            $user_id,
            $validation_error
        );

        return $html;
    }


    public function add_tag_generator()
    {
        if (class_exists('WPCF7_TagGenerator')) {
            $tag_generator = \WPCF7_TagGenerator::get_instance();

            $tag_generator->add(
                'dropbox',
                __('Dropbox Upload', 'integrate-dropbox'),
                [
                    $this,
                    version_compare(WPCF7_VERSION, '6.0', '>=') ? 'tag_generator_body_v6' : 'tag_generator_body',
                ],
                [
                    'version' => '2',
                ]
            );
        }
    }
    public function tag_generator_body_v6($contact_form, $options = '')
    {
        wp_enqueue_script('integrate-dropbox-cf7');
        $tgg = new \WPCF7_TagGeneratorGenerator($options['content']);

        $description = esc_html__('Generate a form-tag for this upload field.', 'integrate-dropbox');
        $form_data   = [
            'id'        => $contact_form->id(),
            'name'      => $contact_form->name(),
            'url'       => get_edit_post_link($contact_form->id()),
            'version'   => WPCF7_VERSION,
            'hash'      => $contact_form->hash(),
            'shortcode' => '[contact-form-7 id="' . $contact_form->hash() . ' title="' . $contact_form->title() . '"]',
        ]

        ?>
        <header data-ccpidb_cf7_data="<?php echo esc_attr(base64_encode(json_encode($form_data))); ?>" class="description-box">
            <h3><?php echo esc_html__('Dropbox Upload', 'integrate-dropbox'); ?></h3>

            <p>
                <?php
                    echo wp_kses(
                        $description,
                        [
                            'a'      => ['href' => true],
                            'strong' => [],
                        ],
                        ['http', 'https']
                    );
        ?>
            </p>
        </header>

        <div class="control-box">
            <?php

        $tgg->print('field_type', [
            'with_required'  => true,
            'select_options' => [
                'dropbox' => esc_html__('Dropbox Upload', 'integrate-dropbox'),
            ],
        ]);

        $tgg->print('field_name');

        ?>

            <fieldset>
                <legend><?php echo esc_html__('Configure Uploader', 'integrate-dropbox'); ?></legend>

                <input type="hidden"
                    data-tag-part="option"
                    data-tag-option="id:"
                    id="<?php echo esc_attr($options['content'] . '-data'); ?>" />

                <button id="ccpidb-form-uploader-config-cf7" type="button"
                    class="ccpidb-form-uploader-trigger ccpidb-form-uploader-trigger-cf7 ccpidb-btn btn-primary">
                    <i class="dashicons dashicons-admin-generic"></i>
                    <span><?php esc_html_e('Configure Uploader', 'integrate-dropbox'); ?></span>
                </button>
            </fieldset>

            <?php
        ?>
        </div>

        <footer class="insert-box">
            <?php
        $tgg->print('insert_box_content');

        $tgg->print('mail_tag_tip');
        ?>
        </footer>
    <?php

    }

    public function tag_generator_body($contact_form, $args = '')
    {
        wp_enqueue_script('integrate-dropbox-cf7');
        $args = wp_parse_args($args, []);
        $type = 'dropbox';

        $description = esc_html__('Generate a form-tag for this upload field.', 'integrate-dropbox');
        ?>
        <div class="control-box">
            <fieldset>
                <legend><?php echo esc_html($description); ?></legend>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Field type', 'integrate-dropbox'); ?></th>
                            <td>
                                <fieldset>
                                    <legend
                                        class="screen-reader-text"><?php echo esc_html__('Field type', 'integrate-dropbox'); ?></legend>
                                    <label>
                                        <input type="checkbox"
                                            name="required" /> <?php echo esc_html__('Required field', 'integrate-dropbox'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($args['content'] . '-name'); ?>">
                                    <?php echo esc_html__('Name', 'integrate-dropbox'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="name" class="tg-name oneline" id="<?php echo esc_attr($args['content'] . '-name'); ?>" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="<?php echo esc_attr($args['content'] . '-data'); ?>">
                                    <?php echo esc_html__('Configure', 'integrate-dropbox'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="hidden" name="data" class="option oneline" id="<?php echo esc_attr($args['content'] . '-data'); ?>" />

                                <button id="ccpidb-form-uploader-config-cf7" type="button"
                                    class="ccpidb-form-uploader-trigger ccpidb-form-uploader-trigger-cf7 ccpidb-btn btn-primary">
                                    <i class="dashicons dashicons-admin-generic"></i>
                                    <span><?php esc_html_e('Configure Uploader', 'integrate-dropbox'); ?></span>
                                </button>
                            </td>
                        </tr>

                    </tbody>
                </table>
            </fieldset>
        </div>

        <div class="insert-box">
            <input type="text" name="<?php echo esc_attr($type); ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

            <div class="submitbox">
                <input type="button" class="button button-primary insert-tag"
                    value="<?php echo esc_attr__('Insert Tag', 'integrate-dropbox'); ?>" />
            </div>

            <br class="clear" />

            <p class="description mail-tag">
                <label for="<?php echo esc_attr($args['content'] . '-mailtag'); ?>">
                    <?php printf('To list the uploads in your email, insert the mail-tag (%s) in the Mail tab.', '<strong><span class="mail-tag"></span></strong>'); ?>
                    <input type="text" class="mail-tag code ccpidb-hidden" readonly="readonly"
                        id="<?php echo esc_attr($args['content'] . '-mailtag'); ?>" />
                </label>
            </p>
        </div>
<?php
    }

    public function validate_field($result, $tag)
    {
        $submission = \WPCF7_Submission::get_instance();

        if (! $submission) {
            return $result;
        }

        $value       = $submission->get_posted_data($tag->name);
        $is_required = '*' === substr($tag->type, -1);

        if ($is_required && empty($value)) {
            $result->invalidate($tag, __('This field is required.', 'integrate-dropbox'));

            return $result;
        }

        return $result;
    }
}
