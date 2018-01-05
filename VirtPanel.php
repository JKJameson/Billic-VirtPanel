<?php
class VirtPanel {
	public $settings = array(
		'orderform_vars' => array(
			'server_type',
			'ipv4',
			'ipv6',
			'server_pool',
			'disk',
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
	function user_cp($array) {
		global $billic, $db;
		$auth = $this->curl('api/login_auth.php?type=vm&username=' . urlencode($array['service']['username']));
		if (!empty($params['serverip'])) {
			$host = $params['serverip'];
		} else {
			$host = $params["serverhostname"];
		}
		echo '<div align="center"><a href="' . get_config('virtpanel_url') . '/?login&username=' . urlencode($array['service']['username']) . '&auth=' . urlencode($auth) . '" class="btn btn-primary btn-xl" target="_blank">&raquo; Click Here to access the Control Panel &laquo;</a></div>';
	}
	function suspend($array) {
		global $billic, $db;
		$service = $array['service'];
		$data = $this->curl('/?vm&action=view&vm=' . urlencode($service['username']) . '&subaction=suspend&full=true');
		if (stripos($data, 'cURL error') !== false) {
			return $data;
		}
		if (stripos($data, 'Successfully Updated') !== false || stripos($data, 'VPS does not exist') !== false) {
			return true;
		} else {
			return $this->get_errors($data);
		}
	}
	function unsuspend($array) {
		global $billic, $db;
		$service = $array['service'];
		$data = $this->curl('/?vm&action=view&vm=' . $service['username'] . '&subaction=unsuspend&full=true');
		if (stripos($data, 'cURL error') !== false) {
			return $data;
		}
		if (stripos($data, 'success') !== false || stripos($data, 'Account does not exist') !== false) {
			return true;
		} else {
			return $this->get_errors($data);
		}
	}
	function terminate($array) {
		global $billic, $db;
		$service = $array['service'];
		//$this->getips($service);
		if (get_config('virtpanel_url') == 'https://cp.servebyte.com') {
			$ips = explode(',', $service['ipaddresses']);
			foreach ($ips as $ip) {
				$row = $db->q('SELECT * FROM `rdns` WHERE `ip` = ?', $ip);
				$row = $row[0];
				$hostname = str_replace('.in-addr.arpa', '.srvbyt.com', $row['zone']);
				$db->q('UPDATE `rdns` SET `hostname` = ?, `email_blocked` = ?, `update_email_block` = ? WHERE `zone` = ?', $hostname, 1, 1, $row['zone']);
			}
		}
		$db->q('UPDATE `services` SET `ipaddresses` = ? WHERE `username` = ?', '', $service['username']);
		$data = $this->curl('/?vm&vm[]=' . urlencode($service['username']) . '&delete=Delete');
		if (stripos($data, 'cURL error') !== false) {
			return $data;
		}
		if (stripos($data, 'Successfully Deleted') !== false || stripos($data, 'Account does not exist') !== false) {
			return true;
		} else {
			return $this->get_errors($data);
		}
	}
	function create($array) {
		global $billic, $db;
		$vars = $array['vars'];
		$service = $array['service'];
		$plan = $array['plan'];
		$user_row = $array['user'];
		if (empty($service['username'])) {
			$username = $this->generate_service_username($array);
		} else {
			$username = $service['username'];
		}
		if (empty($service['password'])) {
			$password = strtolower($billic->rand_str(10));
		} else {
			$password = $billic->decrypt($service['password']);
		}
		$db->q('UPDATE `services` SET `username` = ?, `password` = ? WHERE `id` = ?', $username, $billic->encrypt($password) , $service['id']);
		$bandwidth = $vars['bandwidth'];
		if (strtolower($vars['bandwidth']) == 'unlimited') {
			$bandwidth = 'unlimited';
		} else {
			$bandwidth = round($billic->units2MB($bandwidth) / 1024);
		}
		$url = '/?vm&action=create';
		$url.= '&type=' . urlencode($vars['server_type']);
		$url.= '&username=' . urlencode($username);
		$url.= '&password=' . urlencode($password);
		$url.= '&email=' . urlencode($user_row['email']);
		$url.= '&hostname=' . urlencode($service['domain']);
		$url.= '&plan=' . urlencode('-custom-');
		$url.= '&ip_addresses_from_pool=' . urlencode($vars['ipv4']);
		$url.= '&ipv6=' . urlencode($vars['ipv6']);
		$url.= '&server=' . urlencode('(Automatic)');
		$url.= '&server_pool=' . urlencode($vars['server_pool']);
		$url.= '&create=create';
		$url.= '&disk_quota=' . $billic->units2MB($vars['disk']);
		$url.= '&memory=' . $billic->units2MB($vars['memory']);
		$url.= '&cpu_clock=' . urlencode($vars['cpu']);
		$url.= '&cpu_num=' . urlencode($vars['cpu_cores']);
		$url.= '&bandwidth=' . $bandwidth;
		$url.= '&port_speed=' . urlencode($vars['port_speed'] * 1024);
		$url.= '&allow_rebuild=1';
		$url.= '&domains=' . urlencode($vars['max_domains']);
		if (!empty($vars['template'])) {
			$url.= '&template=' . urlencode($vars['template']);
		}
		$url.= '&continue=1';
		$data = $this->curl($url);
		if (stripos($data, 'cURL error') !== false) {
			return $data;
		}
		if (stripos($data, 'CREATED_SUCCESSFULLY') !== false) {
			$this->getips($service);
			return true;
		} else {
			return $this->get_errors($data);
		}
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
	function curl($action) {
		if ($this->ch === null) {
			$this->ch = curl_init();
			curl_setopt_array($this->ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => false,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_ENCODING => "",
				CURLOPT_USERAGENT => "Curl/Billic",
				CURLOPT_AUTOREFERER => true,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 60,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_SSL_VERIFYPEER => true,
			));
		}
		if (preg_match('/\?/', $action)) {
			$next = '&';
		} else {
			$next = '?';
		}
		curl_setopt($this->ch, CURLOPT_URL, get_config('virtpanel_url') . '/' . $action . $next . 'apikey=' . urlencode(get_config('virtpanel_apikey')));
		$data = curl_exec($this->ch);
		if (curl_errno($this->ch) > 0) {
			return 'Curl error: ' . curl_error($this->ch);
		}
		$data = trim($data);
		return $data;
	}
	function getips($service) {
		global $billic, $db;
		$data_ip = $this->curl('/api/get_ip.php?username=' . urlencode($service['username']));
		$ips = explode(PHP_EOL, $data_ip);
		$ip_list = '';
		foreach ($ips as $ip) {
			if (!$this->is_ip_address($ip)) {
				return 'Failed to get IP Addresses';
			}
			$ip_list.= $ip . ', ';
		}
		$ip_list = substr($ip_list, 0, -2);
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
		$data = $this->curl('/?vm&action=view&vm=' . urlencode($service['username']) . '&subaction=limits' . '&disk_quota=' . urlencode($plan['disk'] * 1024) . '&memory=' . urlencode($plan['memory'] * 1024) . '&cpu_clock=0' . '&cpu_clock=' . urlencode($plan['cpu']) . '&cpu_num=' . urlencode($plan['cpu_cores']) . '&bandwidth=' . urlencode($plan['bandwidth']) . '&port_speed=' . urlencode($plan['port_speed'] * 1024) . '&continue=1');
		if (stripos($data, 'cURL error') !== false) {
			return $data;
		}
		if (stripos($data, 'Successfully Updated') !== false || stripos($data, 'VPS does not exist') !== false) {
			return true;
		} else {
			return $this->get_errors($data);
		}
	}
	function get_errors($data) {
		global $billic, $db;
		preg_match_all('~<error>(.*?)</error>~', $data, $matches);
		$errors = implode('<br>', $matches[1]);
		preg_match('~<b>Fatal Error:</b> (.*)~', $data, $match);
		$errors.= $match[1];
		if (stripos($data, 'Task in progress') !== false) {
			$errors.= 'A task is already in progress';
		}
		if (empty($errors)) {
			$errors = curl_error($this->ch);
		}
		if (empty($errors)) {
			$errors = strip_tags($data);
		}
		return $errors;
	}
	function service_info($params) {
		$ret = '';
		if (!empty($params['service']['ipaddresses'])) {
			$ret.= 'IP: ' . $params['service']['ipaddresses'] . '<br>';
		}
		if (!empty($params['vars']['memory'])) {
			$ret.= 'RAM: ' . $params['vars']['memory'] . '<br>Disk: ' . $params['vars']['disk'] . '<br>Bandwidth: ' . $params['vars']['bandwidth'] . ' @' . $params['vars']['port_speed'] . ' Mbps<br>CPU: ' . $params['vars']['cpu'] . '% of ' . $params['vars']['cpu_cores'] . ' Core' . ($params['vars']['cpu_cores'] > 1 ? 's' : '') . '<br>IPv4: ' . $params['vars']['ipv4'] . '<br>IPv6: ' . $params['vars']['ipv6'];
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
				$ram = $billic->units2MB($ram);
				$disk = $billic->units2MB($disk);
				if ($servers === null) {
					$servers = $this->curl('/api/list_servers_full.php');
					$servers = json_decode($servers, true);
					if (!is_array($servers)) {
						return;
					}
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
