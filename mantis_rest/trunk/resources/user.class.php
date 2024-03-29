<?php
class User extends Resource
{
	public static $mantis_attrs = array('username', 'password', 'realname', 'email',
		'date_created', 'last_visit', 'enabled', 'protected', 'access_level', 'login_count',
		'lost_password_request_count', 'failed_login_count');
	public static $rsrc_attrs = array('username', 'password', 'realname', 'email',
		'date_created', 'last_visit', 'enabled', 'protected', 'access_level', 'login_count',
		'lost_password_request_count', 'failed_login_count');

	public static function get_url_from_mantis_id($user_id)
	{
		/**
		 *      Returns the URL for the resource corresponding to the user with the
		 *      given Mantis user ID.
		 */
		$config = get_config();
		return $config['paths']['api_url'] . "/users/$user_id";
	}

	public static function get_mantis_id_from_url($url)
	{
		$matches = array();
		if (preg_match('!/(\d+)!', $url, &$matches)) {
			return $matches[1];
		} else {
			throw new HTTPException(404, "Unknown user '$matches[1]'");
		}
	}

	function __construct()
	{
		$this->mantis_data = array();
		$this->rsrc_data = array();
	}

	protected function _get_mantis_attr($attr_name)
	{
		if ($attr_name == 'enabled' || $attr_name == 'protected') {
			return $this->rsrc_data[$attr_name] ? 1 : 0;
		} elseif ($attr_name == 'access_level') {
			return get_string_to_enum(config_get('access_levels_enum_string'),
				$this->rsrc_data['access_level']);
		} elseif ($attr_name == 'date_created' || $attr_name == 'last_visit') {
			return date_to_sql_date($this->rsrc_data[$attr_name]);
		} elseif (in_array($attr_name, User::$mantis_attrs)) {
			return ($this->rsrc_data[$attr_name]);
		} else {
			throw new HTTPException(415, "Unknown resource attribute: $attr_name");
		}
	}

	protected function _get_rsrc_attr($attr_name)
	{
		if ($attr_name == 'password') {
			return '********';
		} elseif ($attr_name == 'enabled' || $attr_name == 'protected') {
			return !!$this->mantis_data[$attr_name];
		} elseif ($attr_name == 'access_level') {
			return get_enum_to_string(config_get('access_levels_enum_string'),
				$this->mantis_data['access_level']);
		} elseif ($attr_name == 'date_created' || $attr_name == 'last_visit') {
			return date_to_iso_date($this->mantis_data[$attr_name]);
		} elseif (in_array($attr_name, array('login_count',
			'lost_password_request_count',
			'failed_login_count'))) {
			return (int)$this->$attr_name;
		} else {
			return $this->mantis_data[$attr_name];
		}
	}

	public function populate_from_db()
	{
		/**
		 * 	Populates the instance from the Mantis datbaase.
		 */
		$user_table = config_get('mantis_user_table');
		$query = "SELECT u.username,
				 u.realname,
				 u.email,
				 u.date_created,
				 u.last_visit,
				 u.enabled,
				 u.protected,
				 u.access_level,
				 u.login_count,
				 u.lost_password_request_count,
				 u.failed_login_count
			  FROM $user_table u
			  WHERE u.id='$this->user_id'";
		$result = db_query($query);

		$row = db_fetch_array($result);
		$col_names = array('username', 'realname', 'email', 'date_created',
				   'last_visit', 'enabled', 'protected', 'access_level',
				   'login_count', 'lost_password_request_count',
				   'failed_login_count');
		for ($i = 0; $i < count($col_names); $i++) {
			$this->mantis_data[$col_names[$i]] = $row[$i];
		}
		foreach (User::$rsrc_attrs as $a) {
			$this->rsrc_data[$a] = $this->_get_rsrc_attr($a);
		}
	}

	public function populate_from_repr($repr)
	{
		/**
		 * 	Populates the instance from the representation in the request body.
		 *
		 * 	If the incoming array doesn't have all the keys in $mantis_attrs, or if
		 * 	it's not an array at all, we throw a 415.
		 */
		$this->rsrc_data = json_decode($repr, TRUE);
		if (!is_array($this->rsrc_data)) {
			throw new HTTPException(415, "Incoming representation not an array");
		}
		$diff = array_diff(User::$rsrc_attrs, array_keys($this->rsrc_data));
		if ($diff) {
			throw new HTTPException(415, "Incoming user representation missing keys: " .
				implode(', ', $diff));
		}

		foreach (User::$mantis_attrs as $a) {
			$this->mantis_data[$a] = $this->_get_mantis_attr($a);
		}
	}

	public function get($request)
	{
		/**
		 *      Returns a Response with a representation of the user.
		 *
		 *      @param $request - The Request we're responding to
		 */
		$this->user_id = User::get_mantis_id_from_url($request->url);
		if (!access_has_global_level(config_get('manage_user_threshold'))
				&& auth_get_current_user_id() != $this->user_id) {
			throw new HTTPException(403, "Access denied to user $this->user_id's info");
		}
		$this->populate_from_db();

		$resp = new Response();
		$resp->status = 200;
		$resp->body = $this->_repr($request);
		return $resp;
	}

	public function put($request)
	{
		/**
		 * 	Updates the user.
		 *
		 *      @param $request - The Request we're responding to
		 */
		$this->user_id = User::get_mantis_id_from_url($request->url);
		if (!access_has_global_level(config_get('manage_user_threshold'))
				&& auth_get_current_user_id() != $this->user_id) {
			throw new HTTPException(403,
				"Access denied to edit user $this->user_id's info");
		}
		$this->populate_from_repr($request->body);

		# Do some validation on the inputs (from Mantis's user_create())
		$username = db_prepare_string($this->rsrc_data['username']);
		$realname = db_prepare_string($this->rsrc_data['realname']);
		$password = db_prepare_string($this->rsrc_data['password']);
		$email = db_prepare_string($this->rsrc_data['email']);
		$access_level = db_prepare_int(
			get_string_to_enum(config_get('access_levels_enum_string'),
				$this->rsrc_data['access_level']));
		$protected = db_prepare_bool($this->rsrc_data['protected']);
		$enabled = db_prepare_bool($this->rsrc_data['enabled']);

		user_ensure_name_valid($username);
		user_ensure_realname_valid($realname);
		user_ensure_realname_unique($username, $realname);
		email_ensure_valid($email);

		# The cookie string is based on email and username, so if either of those changed,
		# we have to change the cookie string.
		$user_row = user_get_row($this->user_id);
		$username_key = array_key_exists('username', $user_row) ? 'username' : 1;
		$email_key = array_key_exists('email', $user_row) ? 'email' : 3;
		$cookie_string_key = array_key_exists('cookie_string', $user_row) ?
			'cookie_string' : 13;
		if ($user_row[$username_key] != $username || $user_row[$email_key] != $email) {
			$seed = $email . $username;
			$cookie_string = auth_generate_unique_cookie_string($seed);
		} else {
			$cookie_string = $user_row[$cookie_string_key];
		}

		$password_hash = auth_process_plain_password($password);

		$user_table = config_get('mantis_user_table');
		$query = "UPDATE  $user_table
				SET username = '$username',
				    realname = '$realname',
				    email = '$email',
				    password = '$password_hash',
				    enabled = $enabled,
				    protected = $protected,
				    access_level = $access_level,
				    cookie_string = '$cookie_string'
				WHERE id = $this->user_id;";
		db_query($query);

		$resp = new Response();
		$resp->status = 204;
		return $resp;
	}
}
?>
