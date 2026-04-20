<?php

namespace CodeConfig\IDB;

use CodeConfig\IDB\Utils\Singleton;

defined('ABSPATH') or exit('Hey, what are you doing here? You silly human!');

class AdminNotice
{
    use Singleton;
    private $notices = [];
    private $dismissed_option = 'dismissed_admin_notices';
    
    public function doHooks() {
        add_action('admin_notices', [$this, 'displayNotices']);
    }
    
    public function addNotice($id, $message, $classes = ['ccpidb-notice'], $type = 'info', $dismissible = false, $location = 'admin_notices', $screens = []) {
        $this->notices[$id] = [
            'message' => $message,
            'type' => $type,
            'dismissible' => $dismissible,
            'location' => $location,
            'screens' => $screens,
            'classes' => $classes
        ];
        
        if ($location !== 'admin_notices') {
            add_action($location, [$this, 'displayNotices']);
        }
    }
    
    public function displayNotices() {
        $current_screen = get_current_screen();
        $current_hook = current_action();
        $dismissed = get_option($this->dismissed_option, []);
        
        foreach ($this->notices as $id => $notice) {
            if (in_array($id, $dismissed)) {
                continue;
            }
            
            if ($notice['location'] !== $current_hook) {
                continue;
            }
            
            if (!empty($notice['screens']) && $current_screen) {
                if (!in_array($current_screen->id, $notice['screens'])) {
                    continue;
                }
            }
            
            $this->renderNotice($id, $notice);
        }
    }
    
    private function renderNotice($id, $notice) {
        $classes = array_merge(
            ['notice', 'notice-' . $notice['type'], 'ccpidb-notice'],
            $notice['classes']
        );
        
        if ($notice['dismissible']) {
            $classes[] = 'is-dismissible';
            $classes[] = 'custom-dismissible-notice';
        }
        
        printf(
            '<div class="%s" data-notice-id="%s"><p>%s</p></div>',
            esc_attr(implode(' ', array_unique($classes))),
            esc_attr($id),
            wp_kses_post($notice['message'])
        );
    }
    
    public function removeNotice($id) {
        if (isset($this->notices[$id])) {
            unset($this->notices[$id]);
        }
    }
    
    public function clearDismissed() {
        delete_option($this->dismissed_option);
    }
}
