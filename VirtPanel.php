<?php
class VirtPanel {
	public $settings = array(
		'orderform_vars' => array(
			'server_type',
			'ipv4',
			'ipv6',
			'server_pool',
			'disk',
			'disk_iops',
			'disk_speed',
			'memory',
			'bandwidth',
			'cpu',
			'cpu_cores',
			'port_speed',
			'domain',
			'template'
		) ,
		'description' => 'Automate the provisioning of VPS through VirtPanel.',
	);
	public $ch; // curl handle
	function generate_service_username($array) {
		$username = '';
		$firstname = strtolower($array['user']['firstname']);
		$lastname = strtolower($array['user']['lastname']);
		$allowedchars = 'a b c d e f g h i j k l m n o p q r s t u v w x y z';
		$allowedchars = explode(' ', $allowedchars);
		$part = '';
		for ($i = 0;$i < strlen($firstname);$i++) {
			if (in_array($firstname[$i], $allowedchars)) {
				$part.= $firstname[$i];
				break;
			}
		}
		$username.= $part[0];
		$part = '';
		for ($i = 0;$i < strlen($lastname);$i++) {
			if (in_array($lastname[$i], $allowedchars)) {
				$part.= $lastname[$i];
				break;
			}
		}
		$username.= $part[0];
		$username.= $array['service']['id'];
		return $username;
	}
	function units2MB($units) {
		$units = explode(' ', $units);
		switch ($units[1]) {
			case 'MB':
				return floor($units[0]);
			break;
			case 'GB':
				return floor($units[0] * 1024);
			break;
			case 'TB':
				return floor($units[0] * 1024 * 1024);
			break;
			case 'PB':
				return floor($units[0] * 1024 * 1024 * 1024);
			break;
		}
	}
	function user_cp($array) {
		global $billic, $db;
		/*$auth = $this->curl([
			'api' => 'login_auth',
			'type' => 'vm',
			'vm' => $array['service']['username']
		]);
		if (strlen($auth['key'])!=64)
			die('Failed to obtain login_auth key');*/
		if (isset($_POST['reset-password'])) {
			$newpass = $billic->rand_str(8);
			$data = $this->curl([
				'api' => 'vm_set_login_password',
				'vm' => $array['service']['username'],
				'new' => $newpass
			]);
			$array['service']['password'] = $billic->encrypt($newpass);
			$db->q('UPDATE `services` SET `password` = ? WHERE `username` = ?', $array['service']['password'], $array['service']['username']);
		}
		if (isset($_POST['reboot'])) {
			$data = $this->curl([
				'api' => 'vm_reboot',
				'vm' => $array['service']['username']
			]);
		}
		if (isset($_POST['shutdown'])) {
			$data = $this->curl([
				'api' => 'vm_shutdown',
				'vm' => $array['service']['username']
			]);
		}
		$vm = $this->curl([
			'api' => 'vm',
			'vm' => $array['service']['username']
		]);
		if (!is_array($vm)) {
			die($vm);	
		}
		echo '<br>';
		if (isset($_GET['Action'])&&$_GET['Action']=='ChangeOS') {
			$templates = $this->curl([
				'api' => 'templates'
			]);
			echo '<div class="row justify-content-md-center"><div class="col-xs-12 col-sm-6"><table class="table table-striped"><tr><th colspan="2">Install Server</th></tr><tr><td><form method="POST"><div align="center"><select name="template" class="form-control">';
			foreach($templates['rows'] as $template) {
				echo '<option value="'.safe($template['filename']).'">'.safe($template['desc']).'</option>';
			}
			echo '</select><br><input type="submit" name="install" value="Start Install" class="btn btn-success" onClick="return confirm(\'This will delete all of the data inside your server. Are you sure you want to continue?\');"></div></form></td></tr></table></div></div>';
			return;
		}
		echo '<style>.btn-circle {
    width: 16px;
    height: 16px;
    padding: 4px 0px;
    border-radius: 8px;
	cursor: default;
}</style>';
		echo '<div class="row">';
		echo '<div class="col-xs-12 col-sm-4"><table class="table table-striped"><tr><th colspan="2">Connection Info</th></tr><tr><td>IP Address:</td><td>'.$vm['ip_connect'].'</td></tr><tr><td>Username:</td><td>'.$vm['os_user'].'</td></tr></table>
		<br>
		<form method="POST"><table class="table table-striped"><tr><th colspan="2">Control Panel Login Information</th></tr><tr><td width="150">URL</td><td><a href="'.get_config('virtpanel_url').'" target="_new">'.get_config('virtpanel_url').'</a></td></tr><tr><td>Username</td><td>'.$array['service']['username'].'</td></tr><tr><td>Password</td><td><span>'.$billic->decrypt($array['service']['password']).'</span>&nbsp;&nbsp;&nbsp;<input type="submit" name="reset-password" value="Reset Password" class="btn btn-sm btn-primary"></td></tr></table></form></div>';
		echo '<div class="col-xs-12 col-sm-4"><form method="POST"><table class="table table-striped"><tr><th colspan="2">Power Control</th></tr><tr><td><button type="button" class="btn btn-'.($vm['status']=='running'?'success':'danger').' btn-circle"></button> '.ucwords($vm['status']).'<span style="float:right"><input type="submit" name="reboot" value="Reboot" class="btn btn-sm btn-success" onClick="return confirm(\'Are you sure you want to reboot the server?\')"> <input type="submit" name="shutdown" value="Shutdown" class="btn btn-sm btn-danger" onClick="return confirm(\'Are you sure you want to shutdown the server? The server will be inaccessible until it is started again.\')"></div></td></tr></table><br><table class="table table-striped"><tr><th colspan="2">Server Specs</th></tr><tr><td>Disk</td><td>'.round($vm['plan_disk_quota']/1024, 2).' GB</td></tr><tr><td>RAM</td><td>'.round($vm['plan_memory']/1024, 1).' GB</td></tr><tr><td>CPU</td><td>'.$vm['plan_cpu_num'].' core'.($vm['plan_cpu_num']>1?'s':'').'</td></tr><tr><td>Port Speed</td><td>'.round($vm['plan_port_speed']/1024/1024, 1).' Mbps</td></tr></table></form></div>';
		$template = str_replace('.lzo', '', $vm['template']);
		$template = str_replace('-', ' ', $template);
		echo '<div class="col-xs-12 col-sm-4"><table class="table table-striped"><tr><th colspan="2">Operating System</th></tr><tr><td>'.$template.'</td></tr></table></div>';
		echo '</div>';
	}
	function suspend($array) {
		global $billic, $db;
		$service = $array['service'];
		$data = $this->curl([
			'api' => 'vm_suspend',
			'vm' => $array['service']['username'],
			'power' => 'true',
			'wait' => 'true',
		]);
		if (is_array($data) && $data['status']=='ok') return true; else return $data;
	}
	function unsuspend($array) {
		global $billic, $db;
		$data = $this->curl([
			'api' => 'vm_unsuspend',
			'vm' => $array['service']['username'],
			'power' => 'true',
		]);
		if (is_array($data) && ($data['status']=='ok' || isset($data['task']))) return true; else return $data;
	}
	function terminate($array) {
		global $billic, $db;
		$service = $array['service'];
		//$this->getips($service);
		if (get_config('virtpanel_url') == 'https://cp.servebyte.com') {
			$ips = explode(',', $array['service']['ipaddresses']);
			foreach ($ips as $ip) {
				$row = $db->q('SELECT * FROM `rdns` WHERE `ip` = ?', $ip);
				$row = $row[0];
				$hostname = str_replace('.in-addr.arpa', '.srvbyt.com', $row['zone']);
				$db->q('UPDATE `rdns` SET `hostname` = ?, `email_blocked` = ?, `update_email_block` = ? WHERE `zone` = ?', $hostname, 1, 1, $row['zone']);
			}
		}
		$db->q('UPDATE `services` SET `ipaddresses` = ? WHERE `username` = ?', '', $array['service']['username']);
		$data = $this->curl([
			'api' => 'vm_delete',
			'vm' => $array['service']['username'],
			'wait' => 'yes',
		]);
		if (is_array($data) && $data['status']=='ok') return true; else return $data;
	}
	function create($array) {
		global $billic, $db;
		$vars = $array['vars'];
		$service = $array['service'];
		$plan = $array['plan'];
		$user_row = $array['user'];
		if (empty($service['username'])) $username = $this->generate_service_username($array); else $username = $service['username'];
		if (empty($service['password'])) $password = strtolower($billic->rand_str(10)); else $password = $billic->decrypt($service['password']);
		$db->q('UPDATE `services` SET `username` = ?, `password` = ? WHERE `id` = ?', $username, $billic->encrypt($password) , $service['id']);
		$post = [
			'api' => 'vm_create',
			'type' => $vars['server_type'],
			'username' => $username,
			'password' => $password,
			'email' => $user_row['email'],
			'hostname' => $service['domain'],
			'plan' => '-custom-',
			'ipv4' => $vars['ipv4'],
			'ipv6' => $vars['ipv6'],
			'server' => '(Automatic)',
			'server_pool' => $vars['server_pool'],
			'disk' => $this->units2MB($vars['disk'])/1024,
			'disk_iops' => $vars['disk_iops'],
			'disk_speed' => $vars['disk_speed'],
			'memory' => $this->units2MB($vars['memory'])/1024,
			'cpu_clock' => $vars['cpu'],
			'cpu_cores' => $vars['cpu_cores'],
			'bandwidth' => $this->units2MB($vars['bandwidth'])/1024/1024,
			'port_speed' =>  $vars['port_speed'],
			'allowed_templates' => 'ALL',
			'template' => $vars['template'],
		];
		$data = $this->curl($post);
		if (is_array($data) && $data['status']=='ok') {
			$this->getips($service);
			return true;
		}
		return $data;
	}
	function ordercheck($array) {
		global $billic, $db;
		$vars = $array['vars'];
		if (!(preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $vars['domain']) // valid chars check
		 && preg_match("/^.{1,253}$/", $vars['domain']) // overall length check
		 && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $vars['domain']) // length of each label
		)) {
			$billic->error('Invalid Domain. It should be something like your-domain.com', 'domain');
		}
		return $vars['domain']; // return the domain for the service to be called
		
	}
	function curl($post) {
		if ($this->ch === null) {
			$this->ch = curl_init();
			curl_setopt_array($this->ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => false,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_ENCODING => "",
				CURLOPT_USERAGENT => "Curl/Billic",
				CURLOPT_AUTOREFERER => true,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_POST => true,
			));
		}
		$post['apikey'] = get_config('virtpanel_apikey');
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($this->ch, CURLOPT_URL, get_config('virtpanel_url') . '/api.php');
		$json = curl_exec($this->ch);
		if (curl_errno($this->ch) > 0)
			return 'Curl error: ' . curl_error($this->ch);
		$data = json_decode($json, true);
		if (!is_array($data)) return 'Invalid response from API: '.$json;
		if (!empty($data['error'])) return $data['error'];
		return $data;
	}
	function getips($service) {
		global $billic, $db;
		$vm = $this->curl([
			'api' => 'vm',
			'username' => $service['username'],
		]);
		$ips = explode(',', $service['ipaddresses']);
		$ip_list = '';
		foreach ($ips as $ip) {
			if (!$this->is_ip_address($ip)) continue;
			$ip_list.= $ip.', ';
		}
		$ip_list = substr($ip_list, 0, -2);
		foreach($vm['ipv6'] as $ipv6) {
			$ip_list.= $ipv6['block'].'/'.$block['cidr'].', ';
		}
		$db->q('UPDATE `services` SET `ipaddresses` = ? WHERE `id` = ?', $ip_list, $service['id']);
	}
	function is_ip_address($ip_address) {
		if (preg_match("/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/", $ip_address)) {
			$parts = explode('.', $ip_address);
			foreach ($parts as $ip_parts) {
				if (intval($ip_parts) > 255 || intval($ip_parts) < 0) {
					return false;
				}
			}
			return true;
		} else {
			return false;
		}
	}
	function sync_limits($service) {
		global $billic, $db;
		$plan = $db->q('SELECT * FROM `plans` WHERE `id` = ?', $service['packageid']);
		$plan = $plan[0];
		$limits = [
			'disk_quota' => $plan['disk'],
			'memory' => $plan['memory'],
			'cpu_clock' => $plan['cpu'],
			'cpu_num' => $plan['cpu_cores'],
			'bandwidth' => $plan['bandwidth'],
			'port_speed' => $plan['port_speed'],
		];
		foreach($limits as $limit => $val) {
			$data = $this->curl([
				'api' => 'vm_save',
				'vm' => $service['username'],
				'setting' => $limit,
				'value' => $val,
			]);
			if (is_array($data) && $data['status']=='ok') return true; else return $data;
		}
		return true;
	}
	function nbsp($txt) {
		return str_replace(' ', '&nbsp;', $txt);
	}
	function service_info($params) {
		$ret = '';
		if (!empty($params['vars']['memory'])) {
			$ret.= $this->nbsp('RAM: ' . $params['vars']['memory'] . '<br>Disk: ' . $params['vars']['disk'] . '<br>Bandwidth: ' . $params['vars']['bandwidth'] . ' @' . $params['vars']['port_speed'] . ' Mbps<br>CPU: ' . $params['vars']['cpu'] . '% of ' . $params['vars']['cpu_cores'] . ' Core' . ($params['vars']['cpu_cores'] > 1 ? 's' : '') . '<br>IPv4: ' . $params['vars']['ipv4'] . '<br>IPv6: ' . $params['vars']['ipv6'].'<br>');
		}
		if (!empty($params['service']['ipaddresses'])) {
			$ret.= '<u>IP Addresses</u><br>' . str_replace(',', '<br>', $params['service']['ipaddresses']);
		}
		return $ret;
	}
	function cron() {
		global $billic, $db;
		$servers = null;
		$orderforms = $db->q('SELECT `id` FROM `orderforms` WHERE `module` = \'VirtPanel\'');
		foreach ($orderforms as $orderform) {
			$plans = $db->q('SELECT `id`, `name`, `options` FROM `plans` WHERE `orderform` = ?', $orderform['id']);
			foreach ($plans as $plan) {
				$options = json_decode($plan['options'], true);
				if (!is_array($options)) {
					continue;
				}
				$ram = $options['memory']['value'];
				$disk = $options['disk']['value'];
				$ipv4 = $options['ipv4']['value'];
				$zero_ipv4 = false;
				if ($ipv4 == 0) { // If there are no IP addresses for the plan, prevent a division by zero
					$ipv4 = 1;
					$zero_ipv4 = true;
				}
				$pool = $options['server_pool']['value'];
				if (empty($ram) || empty($disk) || empty($ipv4) || empty($pool)) {
					continue;
				}
				$ram = $this->units2MB($ram);
				$disk = $this->units2MB($disk);
				if ($servers === null) {
					$servers = $this->curl([
						'api' => 'servers',
					]);
					$servers = $servers['rows'];
				}
				$t1 = 0;
				$t2 = 0;
				$t3 = 0;
				$t1_max = 0;
				$t2_max = 0;
				$t3_max = 0;
				foreach ($servers as $server) {
					if ($server['pool'] != $pool || $server['ssh_status'] != 1 || $server['enabled'] != 1) {
						continue;
					}
					$t1_ = floor($server['freemem'] / $ram);
					//$t2_ = floor($server['freedisk'] / $disk);
					$t3_ = floor(($server['ips_total'] - $server['ips_used']) / $ipv4);
					$t1+= $t1_;
					//$t2 += $t2_; // Overselling disk is usually safe
					$t3+= $t3_;
					if ($t1_ > $t1_max) {
						$t1_max = $t1_;
					}
					/*if ($t2_>$t2_max) {
						$t2_max = $t2_;
					}*/
					if ($t3_ > $t3_max) {
						$t3_max = $t3_;
					}
				}
				//echo 'DEBUG -- Pool: "'.$pool.'", Plan: "'.$plan['name'].'", Mem: "'.$t1.'", IPv4: "'.$t3.'"'.PHP_EOL;
				if ($zero_ipv4) {
					$available = $t1;
					$available_max = $t1_max;
				} else {
					$available = min($t1, $t3);
					$available_max = min($t1_max, $t3_max);
				}
				$db->q('UPDATE `plans` SET `available` = ?, `available_max` = ? WHERE `id` = ?', $available, $available_max, $plan['id']);
			}
		}
	}
	function settings($array) {
		global $billic, $db;
		if (empty($_POST['update'])) {
			echo '<form method="POST"><input type="hidden" name="billic_ajax_module" value="VirtPanel"><table class="table table-striped">';
			echo '<tr><th>Setting</th><th>Value</th></tr>';
			echo '<tr><td>VirtPanel URL</td><td><input type="text" class="form-control" name="virtpanel_url" value="' . safe(get_config('virtpanel_url')) . '" style="width: 100%"></td></tr>';
			echo '<tr><td>VirtPanel API Key</td><td><input type="text" class="form-control" name="virtpanel_apikey" value="' . safe(get_config('virtpanel_apikey')) . '" style="width: 100%"></td></tr>';
			echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-default" name="update" value="Update &raquo;"></td></tr>';
			echo '</table></form>';
		} else {
			if (empty($billic->errors)) {
				set_config('virtpanel_url', $_POST['virtpanel_url']);
				set_config('virtpanel_apikey', $_POST['virtpanel_apikey']);
				$billic->status = 'updated';
			}
		}
	}
}
