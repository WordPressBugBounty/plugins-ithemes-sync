<?php

class Ithemes_Sync_Verb_Get_Server_Lite_Details extends Ithemes_Sync_Verb {
	public static $name                      = 'get-server-lite-details';
	public static $description               = 'Retrieve lite details about the server.';
	public static $status_element_name       = 'server-lite';
	public static $show_in_status_by_default = true;

	private $default_arguments = [];


	public function run( $arguments ) {
		$arguments = Ithemes_Sync_Functions::merge_defaults( $arguments, $this->default_arguments );

		return Ithemes_Sync_Functions::get_server_details( $arguments );
	}
}
