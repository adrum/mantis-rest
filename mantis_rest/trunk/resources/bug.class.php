<?php
class Bug extends Resource
{
	/**
	 *      A Mantis bug.
	 */
	static public $mantis_attrs = array('project_id', 'reporter_id', 'handler_id',
		'duplicate_id', 'priority', 'severity', 'reproducibility', 'status', 'resolution',
		'projection', 'category', 'date_submitted', 'last_updated', 'eta', 'os', 'os_build',
		'platform', 'version', 'fixed_in_version', 'target_version', 'build', 'view_state',
		'summary', 'profile_id', 'description', 'steps_to_reproduce',
		'additional_information');

	static public $rsrc_attrs = array('project_id', 'reporter', 'handler', 'duplicate',
		'priority', 'severity', 'reproducibility', 'status', 'resolution', 'projection',
		'category', 'date_submitted', 'last_updated', 'eta', 'os', 'os_build', 'platform',
		'version', 'fixed_in_version', 'target_version', 'build', 'private', 'summary',
		'profile_id', 'description', 'steps_to_reproduce', 'additional_information');

	static function get_mantis_id_from_url($url)
	{
		$matches = array();
		if (preg_match('!/(\d+)/?$!', $url, &$matches)) {
			return (int)$matches[1];
		} else {
			http_error(404, "No such bug: $matches[1]");
		}
	}

	static function get_url_from_mantis_id($bug_id)
	{
		$config = get_config();
		return $config['paths']['api_url'] . "/bugs/$bug_id";
	}

	function __construct($url='http://localhost/bugs/0')
	{
		/**
		 *      Constructs the bug.
		 *
		 *      @param $url - The URL with which this resource was requested
		 */
		$this->bug_id = Bug::get_mantis_id_from_url($url);

		$this->mantis_data = array();
		$this->rsrc_data = array();
	}

	private function _get_mantis_attr($attr_name)
	{
		if ($attr_name == 'reporter_id') {
			return User::get_mantis_id_from_url($this->rsrc_data['reporter']);
		} elseif ($attr_name == 'handler_id') {
			return $this->rsrc_data['handler'] ?
				User::get_mantis_id_from_url($this->rsrc_data['handler']):
				0;
		} elseif ($attr_name == 'duplicate_id') {
			return $this->rsrc_data['duplicate'] ?
				Bug::get_mantis_id_from_url($this->rsrc_data['duplicate']):
				0;
		} elseif (in_array($attr_name, array('priority', 'severity', 'reproducibility',
					'status', 'resolution', 'projection', 'eta'))) {
			return get_string_to_enum(config_get($attr_name."_enum_string"),
				$this->rsrc_data[$attr_name]);
		} elseif ($attr_name == 'date_submitted' || $attr_name == 'last_updated') {
			return date_to_timestamp($this->rsrc_data[$attr_name]);
		} elseif ($attr_name == 'view_state') {
			return $this->rsrc_data['private'] ? VS_PRIVATE : VS_PUBLIC;
		} elseif (in_array($attr_name, Bug::$mantis_attrs)) {
			return $this->rsrc_data[$attr_name];
		} else {
			http_error(415, "Unknown resource attribute: $attr_name");
		}
	}

	private function _get_rsrc_attr($attr_name)
	{
		if ($attr_name == 'reporter') {
			return User::get_url_from_mantis_id($this->mantis_data['reporter_id']);
		} elseif ($attr_name == 'handler') {
			return $this->mantis_data['handler_id'] ?
				User::get_url_from_mantis_id($this->mantis_data['handler_id']):
				"";
		} elseif ($attr_name == 'duplicate') {
			return $this->mantis_data['duplicate_id'] ?
				Bug::get_url_from_mantis_id($this->mantis_data['duplicate_id']):
				"";
		} elseif (in_array($attr_name, array('priority', 'severity', 'reproducibility',
					'status', 'resolution', 'projection', 'eta'))) {
			return get_enum_to_string(config_get($attr_name."_enum_string"),
				$this->mantis_data[$attr_name]);
		} elseif ($attr_name == 'date_submitted' || $attr_name == 'last_updated') {
			return timestamp_to_iso_date($this->mantis_data[$attr_name]);
		} elseif ($attr_name == 'private') {
			return $this->mantis_data['view_state'] == VS_PRIVATE;
		} elseif ($attr_name == 'project_id' or $attr_name == 'profile_id') {
			return (int)$this->mantis_data[$attr_name];
		} elseif (in_array($attr_name, Bug::$rsrc_attrs)) {
			return $this->mantis_data[$attr_name];
		}
	}

	public function populate_from_db()
	{
		/**
		 * 	Populates the Bug instance based on what's in the Mantis DB.
		 */
		$bugdata = bug_get($this->bug_id);
		foreach (Bug::$mantis_attrs as $a) {
			$this->mantis_data[$a] = $bugdata->$a;
		}
		foreach (Bug::$rsrc_attrs as $a) {
			$this->rsrc_data[$a] = $this->_get_rsrc_attr($a);
		}
	}

	public function populate_from_repr()
	{
		/**
		 * 	Populates the Bug instance based on an incoming representation.
		 *
		 * 	No validation is performed on the incoming data.
		 */
		$new_rep = file_get_contents('php://input');
		$this->rsrc_data = json_decode($new_rep, TRUE);
		foreach (Bug::$mantis_attrs as $a) {
			$this->mantis_data[$a] = $this->_get_mantis_attr($a);
		}
	}

	public function to_bugdata()
	{
		/**
		 * 	Returns a BugData object from the Mantis data in the instance.
		 *
		 * 	Expects the instance to have already been populated.
		 */
		$bugdata = new BugData;
		foreach (Bug::$mantis_attrs as $a) {
			$bugdata->$a = $this->mantis_data[$a];
		}
		return $bugdata;
	}

	public function get($request)
	{
		/*
		 *      Returns a Response with a representation of the bug.
		 *
		 *      @param $request - The HTTP request we're responding to
		 */
		if (!bug_exists($this->bug_id)) {
			http_error(404, "No such bug: $this->bug_id");
		}
		if (!access_has_bug_level(VIEWER, $this->bug_id)) {
			http_error(403, "Permission denied");
		}
		$this->populate_from_db();

		$resp = new Response();
		$resp->status = 200;
		$resp->body = $this->repr();
		return $resp;
	}

	public function put($request)
	{
		/*
		 *      Replaces the bug resource using the representation provided.
		 *
		 *      @param $request - The HTTP request we're responding to
		 */
		$this->populate_from_repr();
		bug_update($this->bug_id, $bug_data, true);

		$resp = new Response();
		$resp->status = 204;
		return $resp;
	}

	public function post($request)
	{
		method_not_allowed('POST', array('GET', 'PUT'));
	}
}
?>
