<?php
/**
 * Collection of prefs UI widgets for use with application-specific (a/k/a
 * 'special') configuration.
 *
 * Copyright 2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author   Michael Slusarz <slusarz@horde.org>
 * @category Horde
 * @package  Core
 */
class Horde_Core_Prefs_Ui_Widgets
{
    /* Source selection widget. */

    /**
     * Code to run on init.
     */
    static public function sourceInit()
    {
        Horde::addScriptFile('sourceselect.js', 'horde');
    }

    /**
     * Create code needed for source selection.
     *
     * @param array $data  Data items:
     * <pre>
     * 'mainlabel' - (string) Main label.
     * 'selectlabel' - (array) Selected label.
     * 'sourcelabel' - (string) [OPTIONAL] Source selection label.
     * 'sources' - (array) List of sources - keys are source names. Each
     *             source is an array with two entries - selected and
     *             unselected.
     * 'unselectlabel' - (array) Unselected label.
     * </pre>
     *
     * @return string  HTML UI code.
     */
    static public function source($data)
    {
        $t = $GLOBALS['injector']->createInstance('Horde_Template');

        $t->set('mainlabel', $data['mainlabel']);
        $t->set('selectlabel', $data['selectlabel']);
        $t->set('unselectlabel', $data['unselectlabel']);

        $sources = array();
        foreach ($data['sources'] as $key => $val) {
            $selected = $unselected = array();

            foreach ($val['selected'] as $key2 => $val2) {
                $selected[] = array(
                    'l' => $val2,
                    'v' => $key2
                );
            }

            foreach ($val['unselected'] as $key2 => $val2) {
                $unselected[] = array(
                    'l' => $val2,
                    'v' => $key2
                );
            }

            $sources[$key] = array($selected, $unselected);
        }

        if (count($sources) == 1) {
            $val = reset($sources);
            $t->set('selected', $val[0]);
            $t->set('unselected', $val[1]);
        } else {
            $js = array();
            foreach ($sources as $key => $val) {
                $js[] = array(
                    'selected' => $val[0],
                    'source' => $key,
                    'unselected' => $val[1]
                );
            }
            Horde::addInlineScript(array(
                'HordeSourceSelectPrefs.source_list = ' . Horde_Serialize::serialize($js, Horde_Serialize::JSON, Horde_Nls::getCharset())
            ));
        }

        $t->set('addimg', Horde::img(isset($GLOBALS['nls']['rtl'][$GLOBALS['language']]) ? 'lhand.png' : 'rhand.png', _("Add source")));
        $t->set('removeimg', Horde::img(isset($GLOBALS['nls']['rtl'][$GLOBALS['language']]) ? 'rhand.png' : 'lhand.png', _("Remove source")));

        $t->set('upimg', Horde::img('nav/up.png', _("Move up")));
        $t->set('downimg', Horde::img('nav/down.png', _("Move down")));

        return $t->fetch(HORDE_TEMPLATES . '/prefs/source.html');
    }

    /**
     * Process form data for source selection.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return array  If only one source was originally given, contains the
     *                list of selected values (JSON encoded). If multiple
     *                sources were given, this variable will contain a list of
     *                arrays; each subarray contains the source name and the
     *                list of selected values (JSON encoded).
     */
    static public function sourceUpdate($ui)
    {
        $out = array();

        if (isset($ui->vars->sources)) {
            $out['sources'] = $ui->vars->sources;
        }

        return $out;
    }

    /* Addressbook selection widget. Extends the source widget to handle
     * the special case of addressbook selection. */

    /**
     * Code to run on init for addressbook selection.
     */
    static public function addressbooksInit()
    {
        self::sourceInit();
        Horde::addScriptFile('addressbooksprefs.js', 'horde');
    }

    /**
     * Create code needed for addressbook selection.
     *
     * @param array $data  Data items:
     * <pre>
     * 'fields' - (array) Hash containing addressbook sources as keys and an
     *            array of search fields as values.
     * 'sources' - (array) List of selected addressbooks.
     * </pre>
     *
     * @return string  HTML UI code.
     */
    static public function addressbooks($data)
    {
        global $prefs, $registry;

        $selected = $unselected = array();
        $out = '';

        if (!$registry->hasMethod('contacts/sources') ||
            $prefs->isLocked('search_sources')) {
            return;
        }

        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);

        try {
            $readable = $registry->call('contacts/sources');
        } catch (Horde_Exception $e) {
            $readable = array();
        }

        try {
            $writeable = $registry->call('contacts/sources', array(true));
        } catch (Horde_Exception $e) {
            $writeable = array();
        }

        if (count($readable) == 1) {
            // Only one source, no need to display the selection widget
            $data['sources'] = array_keys($readable);
        }

        foreach ($data['sources'] as $source) {
            if (!empty($readable[$source])) {
                $selected[$source] = $readable[$source];
            }
        }

        foreach (array_diff(array_keys($readable), $data['sources']) as $val) {
            $unselected[$val] = $readable[$val];
        }

        if (!empty($selected) || !empty($unselected)) {
            $out = Horde_Core_Prefs_Ui_Widgets::source(array(
                  'mainlabel' => _("Choose the order of address books to search when expanding addresses."),
                  'selectlabel' => _("Selected address books:"),
                  'sources' => array(array(
                      'selected' => $selected,
                      'unselected' => $unselected
                  )),
                  'unselectlabel' => _("Available address books:")
             ));

            $t->set('selected', count($unselected) > 1);

            $js = array();
            foreach (array_keys($readable) as $source) {
                $tmp = $tmpsel = array();

                try {
                    foreach ($registry->call('contacts/fields', array($source)) as $field) {
                        if ($field['search']) {
                            $tmp[] = array(
                                'name' => $field['name'],
                                'label' => $field['label']
                            );
                            if (isset($data['fields'][$source]) &&
                                in_array($field['name'], $data['fields'][$source])) {
                                $tmpsel[] = $field['name'];
                            }
                        }
                    }
                } catch (Horde_Exception $e) {}

                $js[$source] = array(
                    'entries' => $tmp,
                    'selected' => $tmpsel
                );
            }

            Horde::addInlineScript(array(
                'HordeAddressbooksPrefs.fields = ' . Horde_Serialize::serialize($js, Horde_Serialize::JSON, Horde_Nls::getCharset()),
                'HordeAddressbooksPrefs.nonetext = ' . Horde_Serialize::serialize(_("No address book selected."), Horde_Serialize::JSON, Horde_Nls::getCharset())
            ));
        }

        return $out . $t->fetch(HORDE_TEMPLATES . '/prefs/addressbooks.html');
    }

    /**
     * Process form data for address book selection.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     *
     * @return array  Array with two possible keys: sources and fields.
     *                Sources contains the list of selected addressbooks (JSON
     *                encoded). Fields contains a hash containing sources as
     *                keys and an array of search fields as the value.
     */
    static public function addressbooksUpdate($ui)
    {
        $out = self::sourceUpdate($ui);

        if (isset($ui->vars->sources)) {
            $out['sources'] = $ui->vars->sources;
        }

        if (isset($ui->vars->search_fields)) {
            $out['fields'] = $ui->vars->search_fields;
        }

        return $out;
    }

    /* Alarms selection widget. */

    /**
     * Code to run on init for alarms selection.
     */
    static public function alarmInit()
    {
        Horde::addScriptFile('alarmprefs.js', 'horde');
    }

    /**
     * Create code needed for alarm selection.
     *
     * @param array $data  Data items:
     * <pre>
     * 'helplink' - (string) [OPTIONAL] Help link.
     * 'label' - (string) Label.
     * 'pref' - (string) Preference name.
     * </pre>
     *
     * @return string  HTML UI code.
     */
    static public function alarm($data)
    {
        $pref = $data['pref'];

        Horde::addInlineScript(array(
            'HordeAlarmPrefs.pref = ' . Horde_Serialize::serialize($pref, Horde_Serialize::JSON)
        ));

        $alarm_pref = unserialize($GLOBALS['prefs']->getValue($pref));
        $selected = array_keys($alarm_pref);

        $t = $GLOBALS['injector']->createInstance('Horde_Template');
        $t->setOption('gettext', true);

        $param_list = $select_list = array();

        foreach (Horde_Alarm::notificationMethods() as $method => $params) {
            $select_list[] = array(
                'l' => $params['__desc'],
                's' => in_array($method, $selected),
                'v' => $method
            );

            if (count($params > 1)) {
                $tmp = array(
                    'method' => $method,
                    'param' => array()
                );

                foreach ($params as $name => $param) {
                    if (substr($name, 0, 2) == '__') {
                        continue;
                    }

                    switch ($param['type']) {
                    case 'text':
                        $tmp['param'][] = array(
                            'label' => Horde::label($pref . '_' . $name, $param['desc']),
                            'name' => $pref . '_' . $name,
                            'text' => true,
                            'value' => empty($alarm_pref[$method][$name]) ? '' : htmlspecialchars($alarm_pref[$method][$name])
                            );
                        break;

                    case 'bool':
                        $tmp['param'][] = array(
                            'bool' => true,
                            'checked' => !empty($alarm_pref[$method][$name]),
                            'label' => Horde::label($pref . '_' . $name, $param['desc']),
                            'name' => $pref . '_' . $name
                        );
                        break;

                    case 'sound':
                        $current_sound = empty($alarm_pref[$method][$name])
                            ? ''
                            : $alarm_pref[$method][$name];
                        $sounds = array();
                        foreach (Horde_Themes::soundList() as $key => $val) {
                            $sounds[] = array(
                                'c' => ($current_sound == $key),
                                'uri' => htmlspecialchars($val->uri),
                                'val' => htmlspecialchars($key)
                            );
                        }
                        $t->set('sounds', $sounds);

                        $tmp['param'][] = array(
                            'sound' => true,
                            'checked' => !$current_sound,
                            'name' => $pref . '_' . $name
                        );
                        break;
                    }
                }

                $param_list[] = $tmp;
            }
        }

        $t->set('desc', Horde::label($pref, $data['label']));
        if (!empty($data['helplink'])) {
            $t->set('helplink', $data['helplink']);
        }
        $t->set('pref', htmlspecialchars($pref));
        $t->set('param_list', $param_list);
        $t->set('select_list', $select_list);

        return $t->fetch(HORDE_TEMPLATES . '/prefs/alarm.html');
    }

    /**
     * Process form data for alarm selection.
     *
     * @param Horde_Core_Prefs_Ui $ui  The UI object.
     * @param array $data              Data items:
     * <pre>
     * 'pref' - (string) Preference name.
     * </pre>
     *
     * @return array  TODO
     */
    static public function alarmUpdate($ui, $data)
    {
        $pref = $data['pref'];
        $methods = Horde_Alarm::notificationMethods();
        $val = (isset($ui->vars->$pref) && is_array($ui->vars->$pref))
            ? $ui->vars->$pref
            : array();
        $value = array();

        foreach ($val as $method) {
            $value[$method] = array();
            if (!empty($methods[$method])) {
                foreach (array_keys($methods[$method]) as $param) {
                    $value[$method][$param] = $ui->vars->get($pref . '_' . $param, '');
                    if (is_array($methods[$method][$param]) &&
                        $methods[$method][$param]['required'] &&
                        ($value[$method][$param] === '')) {
                        $GLOBALS['notification']->push(sprintf(_("You must provide a setting for \"%s\"."), $methods[$method][$param]['desc']), 'horde.error');
                        return null;
                    }
                }
            }
        }

        return $value;
    }

}
