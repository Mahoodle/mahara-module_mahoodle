<?php
/**
 *
 * @package    mahara
 * @subpackage mahoodle
 * @author     Catalyst IT Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL version 3 or later
 * @copyright  For copyright information on Mahara, please see the README file distributed with this software.
 *
 */

defined('INTERNAL') || die();

/**
 * module plugin class. Used for registering the plugin and functions.
 */
class PluginModuleMahoodle extends PluginModule {

    /**
     * Is the plugin activated or not?
     *
     * @return boolean true, if the plugin is activated, otherwise false
     */
    public static function is_active() {
        $active = false;
        if (get_field('module_installed', 'active', 'name', 'mahoodle')) {
            $active = true;
        }
        return $active;
    }

    /**
     * API-Function get the Plugin ShortName
     *
     * @return string ShortName of the plugin
     */
    public static function get_plugin_display_name() {
        return 'mahoodle';
    }

    /**
     * Make the plugin configurable.
     */
    public static function has_config() {
        if (self::is_active()) {
            return true;
        }

        return false;
    }

    /**
     * This function will be run after every upgrade to the plugin.
     */
    public static function postinst($fromversion) {
        require_once(get_config('docroot') . 'webservice/lib.php');
        external_reload_component('module/mahoodle');
    }

    /**
     * Display the plugin configuration form.
     *
     * @return form values.
     */
    public static function get_config_options() {
        $elements = [];
        $elements['moodle_webservice_token'] = array(
            'type'  => 'text',
            'size' => 50,
            'title' => get_string('moodlewebservicetoken', 'module.mahoodle'),
            'description' => get_string('moodlewebservicetokendescription', 'module.mahoodle'),
            'defaultvalue' => get_config_plugin('module', 'mahoodle', 'moodle_webservice_token'),
        );
        $form = ['elements' => $elements];

        return $form;
    }

    /**
     * Save the plugin configuration form submited values.
     */
    public static function save_config_options(Pieform $form, $values) {
        set_config_plugin('module', 'mahoodle', 'moodle_webservice_token', $values['moodle_webservice_token']);
        return true;
    }

    /**
     * Receives notifications to be sent to users and ferries them to the Moodle webservice.
     * @param int $messageid The ID of the notification message in the database
     * @param stdClass $notification The inserted notification
     * @param string $type The type of notification
     * @return stdClass Containing information about the cURL call
     */
    public static function notification_created($messageid, $toinsert, $type) {

        $token = get_config_plugin('module', 'mahoodle', 'moodle_webservice_token');
        if (empty($token)) {
            return new stdClass;
        }

        // First we need the MNET details to contact.
        $sql = '
        SELECT aic.value AS mnethost, aru.remoteusername
          FROM {auth_instance_config} aic
          JOIN {auth_instance} ai ON (aic.instance = ai.id)
          JOIN {usr} u ON (u.authinstance = ai.id)
          JOIN {auth_remote_user} aru ON (aru.authinstance = ai.id AND aru.localusr = u.id)
         WHERE ai.authname = ?
           AND aic.field = ?
           AND u.id = ?';

        $params = [
            'xmlrpc',       // For the ai.authname clause.
            'wwwroot',      // For the aic.field clause.
            $toinsert->usr, // For the u.id clause.
        ];

        $record = get_record_sql($sql, $params);
        if (empty($record)) {
            return new stdClass; // Nothing to do as not an MNET user.
        }

        // Assemble our cURL call.
        $wsparams = [
            'wstoken'            => $token,
            'moodlewsrestformat' => 'json',
            'wsfunction'         => 'local_mahoodle_receive_mahara_notifications',
            'username'           => $record->remoteusername,
            'maharanotifyid'     => $messageid,
            'subject'            => $toinsert->subject,
            'body'               => $toinsert->message,
            'mnethost'           => dropslash(get_config('wwwroot')),
            'type'               => $type,
        ];
        $config = [
            CURLOPT_URL        => $record->mnethost . '/webservice/rest/server.php',
            CURLOPT_POST       => 1,
            CURLOPT_POSTFIELDS => $wsparams,
        ];
        return mahara_http_request($config);
    }

    /**
     * Receives notifications to be sent to users and ferries them to the Moodle webservice.
     * @param array $ids The ids to be marked read
     * @param int $userid The user whose notifications are being read
     * @param string $type The type of notification
     * @return stdClass Containing information about the cURL call
     */
    public static function notification_read($ids, $userid, $type) {

        $token = get_config_plugin('module', 'mahoodle', 'moodle_webservice_token');
        if (empty($token)) {
            return new stdClass;
        }

        // First we need the MNET details to contact.
        $sql = '
        SELECT aic.value AS mnethost, aru.remoteusername
          FROM {auth_instance_config} aic
          JOIN {auth_instance} ai ON (aic.instance = ai.id)
          JOIN {usr} u ON (u.authinstance = ai.id)
          JOIN {auth_remote_user} aru ON (aru.authinstance = ai.id AND aru.localusr = u.id)
         WHERE ai.authname = ?
           AND aic.field = ?
           AND u.id = ?';

        $params = [
            'xmlrpc',  // For the ai.authname clause.
            'wwwroot', // For the aic.field clause.
            $userid,   // For the u.id clause.
        ];

        $record = get_record_sql($sql, $params);
        if (empty($record)) {
            return new stdClass; // Nothing to do as not an MNET user.
        }

        // Assemble our cURL call.
        $wsparams = [
            'wstoken'            => $token,
            'moodlewsrestformat' => 'json',
            'wsfunction'         => 'local_mahoodle_read_mahara_notifications',
            'maharanotifyid'     => implode(',', (array) $ids),
            'mnethost'           => dropslash(get_config('wwwroot')),
            'type'               => $type,
        ];
        $config = [
            CURLOPT_URL        => $record->mnethost . '/webservice/rest/server.php',
            CURLOPT_POST       => 1,
            CURLOPT_POSTFIELDS => $wsparams,
        ];
        return mahara_http_request($config);
    }

    /**
     * Notifies the Moodle webservice of notifications to be deleted (as they have been deleted in Mahara)
     * @param array $ids The ids to be deleted
     * @param int $userid The user whose notifications are being deleted
     * @param string $type The type of notification
     * @return stdClass Containing information about the cURL call
     */
    public static function notification_delete($ids, $userid, $type) {

        $token = get_config_plugin('module', 'mahoodle', 'moodle_webservice_token');
        if (empty($token)) {
            return new stdClass;
        }

        // First we need the MNET details to contact.
        $sql = '
        SELECT aic.value AS mnethost, aru.remoteusername
          FROM {auth_instance_config} aic
          JOIN {auth_instance} ai ON (aic.instance = ai.id)
          JOIN {usr} u ON (u.authinstance = ai.id)
          JOIN {auth_remote_user} aru ON (aru.authinstance = ai.id AND aru.localusr = u.id)
         WHERE ai.authname = ?
           AND aic.field = ?
           AND u.id = ?';

        $params = [
            'xmlrpc',  // For the ai.authname clause.
            'wwwroot', // For the aic.field clause.
            $userid,   // For the u.id clause.
        ];

        $record = get_record_sql($sql, $params);
        if (empty($record)) {
            return new stdClass; // Nothing to do as not an MNET user.
        }

        // Assemble our cURL call.
        $wsparams = [
            'wstoken'            => $token,
            'moodlewsrestformat' => 'json',
            'wsfunction'         => 'local_mahoodle_delete_mahara_notifications',
            'maharanotifyid'     => implode(',', (array) $ids),
            'mnethost'           => dropslash(get_config('wwwroot')),
            'type'               => $type,
        ];
        $config = [
            CURLOPT_URL        => $record->mnethost . '/webservice/rest/server.php',
            CURLOPT_POST       => 1,
            CURLOPT_POSTFIELDS => $wsparams,
        ];
        return mahara_http_request($config);
    }
}
