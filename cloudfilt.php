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

class PlgSystemCloudFilt extends CMSPlugin
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


	public function onAfterRoute()
	{
		if ( ! empty($this->params->get('key_site')) && $this->checkComponentExclude() === false && $this->checkRoleExclude() === false)
		{
			$this->filterRequest();
			$this->addScriptToThePage();
		}
	}

	public function onContentPrepareForm(Joomla\CMS\Form\Form $form, $data)
	{
		if (($form->getName() === 'com_plugins.plugin' && isset($data->element) && $data->element === 'cloudfilt')
			|| $form->getName() === 'com_plugins.plugins.filter'
			|| $form->getName() === 'com_installer.manage.filter')
		{
			try
			{
				$this->checkCredentials();
			}
			catch (Exception $e)
			{
				$this->disablePlgReloadPageShowMsg($e->getMessage());
			}
		}
	}

	protected function addScriptToThePage()
	{
		$script_url = 'https://srv'.$this->params->get('key_site').'.cloudfilt.com/analyz.js?render='.$this->params->get('key_front');

		Factory::getDocument()->addScript(
			$script_url,
			[],
			['async' => true]
		);
	}

	protected function checkCredentials()
	{
		$this->checkEmptyKeys();

		$key_site = $this->getKeySite($this->params->get('key_front'), $this->params->get('key_back'));

		if ($key_site !== $this->params->get('key_site'))
		{
			$this->updateKeySiteParam($key_site);
		}
	}

	protected function updateKeySiteParam(string $key_site)
	{
		$table = new Extension($this->db);
		if ($table->load(['element' => $this->_name]))
		{
			$params             = (new Registry($table->get('params')))->toArray();
			$params['key_site'] = $key_site;
			$table->save(compact('params'));
		}
	}

	protected function checkEmptyKeys()
	{
		$key_front = $this->params->get('key_front');

		if (empty($key_front))
		{
			throw new Exception('Empty front key.');
		}

		$key_back = $this->params->get('key_back');

		if (empty($key_back))
		{
			throw new Exception('Empty back key.');
		}
	}

	protected function disablePlgReloadPageShowMsg(string $message)
	{
		$this->disablePlugin();
		$this->enqueueDisabledMsg($message);
		$this->reloadPage();
	}

	protected function checkComponentExclude()
	{
		$components = $this->params->get('component_exclude', []);

		if (count($components) === 0)
		{
			return false;
		}

		$option = $this->app->input->get('option');

		if (in_array($option, $components))
		{
			return true;
		}
		else
		{
			return false;
		}
	}


	protected function checkRoleExclude(): bool
	{
		$roles = $this->params->get('role_exclude', []);

		if (count($roles) === 0)
		{
			return false;
		}

		$user             = Factory::getUser();
		$groups           = $user->get('groups');
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

		$response = $this->request('https://api'.$this->params->get('key_site').'.cloudfilt.com/phpcurl', [
			'ip'  => $user_ip,
			'KEY' => $this->params->get('key_back'),
			'URL' => Uri::getInstance()->__toString(),
		]);

		if (empty($response['error']) && ! empty($response['body']) && $response['body'] !== 'OK')
		{
			$this->app->redirect('https://cloudfilt.com/stop-'.$user_ip.'-'.$this->params->get('key_front'));
		}
	}

	protected function getKeySite(string $key_front, string $key_back)
	{
		$response = $this->request('https://api.cloudfilt.com/checkcms/joomla.php', compact('key_front', 'key_back'));

		if ( ! empty($response['error']))
		{
			throw new Exception(Text::_('PLG_SYSTEM_CLOUDFILT_CONNECTION_FAILED_MSG'));
		}

		$result = json_decode($response['body'], true);

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

	protected function disablePlugin()
	{
		$table = new Extension($this->db);
		if ($table->load(['element' => $this->_name]))
		{
			$params = (new Registry($table->get('params')))->toArray();
			unset($params['key_site']);
			$table->save(['enabled' => 0, 'params' => $params]);
		}
	}

	protected function enqueueDisabledMsg(string $message)
	{
		$this->app->enqueueMessage(Text::sprintf('PLG_SYSTEM_CLOUDFILT_DISABLED_MSG', $message), 'warning');
	}

	protected function reloadPage()
	{
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
		$body  = curl_exec($ch);
		$error = curl_error($ch);
		curl_close($ch);

		return compact('body', 'error');
	}

}
