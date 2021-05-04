<?php

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Extension;

class PlgSystemCloudfilt extends CMSPlugin
{

    /**
     * @var CMSApplication object
     *
     * @since
     */
    protected $app;


    /**
     * @var \JDatabaseDriver
     *
     * @since
     */
    protected $db;


    protected $autoloadLanguage = true;


    public function onAfterInitialise()
    {
        if ($this->app->isClient('site') && $this->checkRolesExclude() === false)
        {
            $this->filterRequest();
            $this->addScriptToThePage();
        }
    }

    public function onExtensionBeforeSave(string $context, Extension $table, bool $isNew, array $data)
    {
        if ($context === 'com_plugins.plugin' && $data['element'] === 'cloudfilt')
        {
            try
            {
                $key_site = $this->getKeySite($data['params']['key_front'], $data['params']['key_back']);
                $this->bindTableKeySiteParam($table, $key_site);
            }
            catch (Exception $e)
            {
                $this->turnOffPlugin($data['extension_id']);
                $table->setError($e->getMessage());

                return false;
            }
        }
    }

    public function onContentPrepareForm(Joomla\CMS\Form\Form $form)
    {
        if ($form->getName() === 'com_plugins.plugins.filter' || $form->getName() === 'com_installer.manage.filter')
        {
            try
            {
                $this->checkCredentials();
            }
            catch (Exception $e)
            {
                $this->turnOffPlugin(['element' => $this->_name]);
                $this->reloadPageAndShowTurnOffMsg();
            }
        }
    }

    protected function addScriptToThePage()
    {
        $script_url = 'https://srv' . $this->params->get('key_site') . '.cloudfilt.com/analyz.js?render=' . $this->params->get('key_front');

        Factory::getDocument()->addScript(
            $script_url,
            [],
            ['async' => true]
        );
    }

    protected function bindTableKeySiteParam(Extension $table, string $key_site)
    {
        $params             = (new Registry($table->get('params')))->toArray();
        $params['key_site'] = $key_site;
        $table->bind(compact('params'));
    }

    protected function checkCredentials()
    {
        $this->checkEmptyKeys();

        $key_site = $this->getKeySite($this->params->get('key_front'), $this->params->get('key_back'));

        if ($key_site !== $this->params->get('key_site'))
        {
            $table = new Extension($this->db);
            if ($table->load(['element' => $this->_name]))
            {
                $this->bindTableKeySiteParam($table, $key_site);
            }
        }
    }

    protected function checkEmptyKeys()
    {
        $key_front = $this->params->get('key_front');

        if (empty($key_front))
        {
            throw new Exception('Empty front key');
        }

        $key_back  = $this->params->get('key_back');

        if (empty($key_back))
        {
            throw new Exception('Empty back key');
        }
    }

    protected function checkRolesExclude(): bool
    {
        $roles_exclude = $this->params->get('roles_exclude', '0');

        if ($roles_exclude === '0')
        {
            return false;
        }

        $user             = Factory::getUser();
        $groups           = $user->get('groups');
        $roles            = $this->params->get('roles', []);
        $intersect_result = array_intersect($roles, $groups);

        if (count($intersect_result) === 0)
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    protected function filterRequest()
    {
        $user_ip = $this->getUserIP();

        $response = $this->request('https://api' . $this->params->get('key_site') . '.cloudfilt.com/phpcurl', [
            'ip'  => $user_ip,
            'KEY' => $this->params->get('key_back'),
            'URL' => Uri::getInstance()->__toString(),
        ]);

        if (!empty($response) && $response !== 'OK')
        {
            $this->app->redirect('https://cloudfilt.com/stop-' . $user_ip . '-' . $this->params->get('key_front'));
        }
    }

    protected function getKeySite(string $key_front, string $key_back)
    {
        $response = $this->request('https://api.cloudfilt.com/checkcms/joomla.php', compact('key_front', 'key_back'));

        $result = json_decode($response, true);

        if (isset($result['status']))
        {
            if ($result['status'] === 'NO')
            {
                throw new Exception(Text::_('PLG_SYSTEM_CLOUDFILT_STATUS_NO'));
            }

            return $result['site'];
        }
        else
        {
            throw new Exception(Text::_('PLG_SYSTEM_CLOUDFILT_BAD_RESPONSE'));
        }
    }

    protected function turnOffPlugin($key)
    {
        $table = new Extension($this->db);
        $table->load($key);
        $table->save(['enabled' => 0]);
    }

    protected function reloadPageAndShowTurnOffMsg()
    {
        $this->app->enqueueMessage(Text::_('PLG_SYSTEM_CLOUDFILT_TURN_OFF_MSG'), 'error');
        $this->app->redirect(Uri::getInstance()->toString());
    }

    protected function getUserIP()
    {
        $keys = ["REMOTE_ADDR", "HTTP_CLIENT_IP", "HTTP_X_FORWARDED_FOR", "HTTP_X_FORWARDED", "HTTP_FORWARDED_FOR", "HTTP_FORWARDED"];

        foreach ($keys as $key)
        {
            if (isset($_SERVER[$key]) and
                (filter_var($_SERVER[$key], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($_SERVER[$key], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)))
            {
                return $_SERVER[$key];
            }
        }

        return "UNKNOWN";
    }

    protected function request(string $url, array $post_data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

}
