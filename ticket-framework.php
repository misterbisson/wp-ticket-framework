<?php
/*
Plugin Name: WordPress Ticket Framework
Plugin URI: http://wordpress.org/extend/plugins/wp-ticket-framework/
Description: A framework for managing actions over time.
Version: 0.0
Author: Casey Bisson
Author URI: http://maisonbisson.com/
*/

/**
 * WordPress Ticket Framework
 * @package wpTix
 */
class wpTix {

	var $url_base = 'do';
	var $query_var = 'do';

	/**
	 * Old-style constructor. Put all constructor code in __construct()
	 * @internal
	 */
	function wpTix(){
		$this->__construct();
	}

	/**
	 * Standard constructor
	 * @internal
	 */
	function __construct(){
		global $wpdb;

		$this->tickets = $wpdb->prefix . 'tickets';
		$this->ticket_actions = $wpdb->prefix . 'ticket_actions';

		add_action( 'init', array( &$this, 'init' ));
		add_action( 'parse_query', array( &$this, 'parse_query' ), 1 );
		add_action( 'did_ticket', array( &$this, 'did_ticket' ), 11 );

		register_activation_hook( __FILE__, array( &$this, '_activate' ));
	}

	/**
	 * Init action handler. Sets up rewrites for tickets
	 * @internal
	 */
	function init(){
		// add the rewrite rules
		add_rewrite_tag( '%'. $this->query_var .'%', '[^/]+' );
		add_rewrite_rule( $this->query_var .'/([^/]+)' , 'index.php?'. $this->query_var .'=$matches[1]', 'top' );
	}

	/**
	 * Parse_query action handler. Closes the requested ticket.
	 * @internal
	 */
	function parse_query( $query ){
		if( !empty( $query->query_vars[ $this->query_var ] ))
			$this->do_ticket( $query->query_vars[ $this->query_var ] );
	}

	/**
	 * Configures whether or not to delete the ticket & redirect to siteurl when the ticket is closed
	 * @param boolean $yes whether or not to call self::did_ticket on ticket close
	 */
	function clean_up_after( $yes = TRUE ){
		if( $yes )
			add_action( 'did_ticket', array( &$this, 'did_ticket' ), 11 );
		else
			remove_action( 'did_ticket', array( &$this, 'did_ticket' ), 11 );
	}

	/**
	 * Get the URL for a given ticket
	 * @param string $ticket_name
	 * @return string URL
	 */
	function get_url( $ticket_name ){
		global $wp_rewrite;

		if ( empty( $wp_rewrite->permalink_structure ))
			return get_settings( 'siteurl' ) .'/?'. $this->query_var .'='. urlencode( $ticket_name );
		else
			return get_settings( 'siteurl' ) .'/'. $this->url_base .'/'. urlencode( $ticket_name );
	}


	/**
	 * Test if a ticket exists with a given name
	 * @param string $ticket_name Unique ticket name
	 * @return wpTix|false Ticket or false if not found
	 */
	function is_ticket( $ticket_name ){
		global $wpdb;

		$ticket_name = substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $ticket_name ), 0, 32 );
		if( empty( $ticket_name ))
			return FALSE;


		if ( !$ticket = wp_cache_get( $ticket_name, 'tickets' )) {
			$ticket = $wpdb->get_row( $wpdb->prepare("SELECT t.ticket_id, t.ticket, a.action, t.arg FROM ( SELECT * FROM $this->tickets t USE INDEX (ticket) WHERE ticket = %s LIMIT 1 ) t JOIN $this->ticket_actions a ON a.action_id = t.action_id LIMIT 1", $ticket_name) );

			wp_cache_add( $ticket_name, $ticket, 'tickets' );
		}

		if( ! $ticket )
			return FALSE;

		$ticket->arg = maybe_unserialize( $ticket->arg );
		$ticket->url = $this->get_url( $ticket->ticket );

		return $ticket;
	}

	/**
	 * Create a ticket
	 * @param string $action Hook to invoke when the ticket is closed
	 * @param string $ticket_name Unique ticket name
	 * @param mixed $arg Argument(s) to pass to $action
	 * @return wpTix|false The ticket that was created, or false on error.
	 */
	function register_ticket( $action, $ticket_name, $arg = '' ){
		global $wpdb;

		$ticket = array();

		$ticket['action_id'] = $this->_insert_action( $action );
		if( !$ticket['action_id'] )
			return FALSE;

		$ticket['ticket'] = substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $ticket_name ), 0, 32 );
		if( empty( $ticket['ticket'] ))
			return FALSE;

		$ticket['arg'] = maybe_serialize( $arg );

		if ( false === $wpdb->insert( $this->tickets, $ticket) ){
			new WP_Error('db_insert_error', __('Could not insert new ticket_action into the database'), $wpdb->last_error);
			return FALSE;
		}

		wp_cache_add( $ticket['ticket'], $ticket, 'tickets' );

		return $this->is_ticket( $ticket_name );
	}

	/**
	 * Update a ticket's action or arg array.
	 */
	function update_ticket( $ticket ) {
		global $wpdb;

		if( !$ticket->ticket_id ) {
			return false;
		}

		$ticket->action_id = $this->_insert_action( $ticket->action );
		if( !$ticket->action_id )
			return FALSE;

		$ticket->arg = maybe_serialize( $ticket->arg );

		$data = array('action_id' => $ticket->action_id, 'arg' => $ticket->arg);
		$where = array('ticket' => $ticket->ticket);

		if ( false === $wpdb->update( $this->tickets, $data, $where ) ){
			new WP_Error('db_update_error', __('Could not update ticket in the database'), $wpdb->last_error);
			return FALSE;
		}

		wp_cache_set( $ticket->ticket, $ticket, 'tickets' );

		return $this->is_ticket( $ticket->ticket );
	}//end update_ticket

	/**
	 * Close a ticket and perform its corresponding action
	 * @param string $ticket_name Unique ticket name
	 */
	function do_ticket( $ticket_name ){
		global $wpdb;

		nocache_headers();

		$ticket = $this->is_ticket( $ticket_name );
		if( ! $ticket )
			die( wp_redirect( get_settings( 'siteurl' ), '301'));

		// do the specified action
		do_action( $ticket->action, $ticket->arg, $ticket );

		// clean up after doing the ticket
		do_action( 'did_ticket', $ticket );
	}

	/**
	 * Close (delete) a ticket and redirect to siteurl
	 * @internal
	 */
	function did_ticket( $ticket ){
		$this->delete_ticket( $ticket->ticket );
		die( wp_redirect( get_settings( 'siteurl' ), '301'));
	}

	/**
	 * Delete a ticket
	 * @param string $ticket_name Unique ticket name
	 * @return boolean sucessful deletion
	 */
	function delete_ticket( $ticket_name ){
		global $wpdb;

		$ticket = $this->is_ticket( $ticket_name );
		if( ! $ticket )
			return FALSE;

		wp_cache_delete( $ticket_name, 'tickets' );

		return $wpdb->query( $wpdb->prepare( "DELETE FROM $this->tickets WHERE ticket_id = %d", $ticket->ticket_id ));
	}



	/**
	 * Generate a 32-character random, unique ticket name
	 * @return string ticket name
	 */
	function generate_md5() {
		while( TRUE ){
			$ticket_name = md5( uniqid( rand(), true ));
			if( ! $this->is_ticket( $ticket_name ))
				return $ticket_name;
		}
	}

	/**
	 * Generate a random, unique ticket name with a given length, using a given alphabet
	 * @param int $len string length
	 * @param string $alphabet valid characters
	 * @return string random string
	 */
	function generate_string( $len = 5, $alphabet = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' ){
		while( TRUE ){
			$ticket_name = $this->_generate_string( $len , $alphabet );
			if( ! $this->is_ticket( $ticket_name ))
				return $ticket_name;
		}
	}

	/**
	 * Generate a random string of a given length, using a given alphabet
	 * @static
	 * @param int $len string length
	 * @param string $alphabet valid characters
	 * @return string random string
	 */
	function _generate_string( $len = 5, $alphabet = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' ){
		$key = '';
		for( $i=0; $i < $len; $i++ )
			$key .= $alphabet[ rand( 0, ( strlen( $alphabet )) -1 ) ];

		return $key;
	}

	/**
	 * @internal
	 */
	function _is_action( $action ) {
		global $wpdb;

		$action = substr( preg_replace( '/[^a-zA-Z0-9\-_]/', '', $action ), 0, 64 );
		if( empty( $action ))
			return FALSE;

		if ( !$action_id = wp_cache_get( $action, 'ticket_actions' )) {
			$action_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT action_id FROM $this->ticket_actions WHERE action = %s", $action ));

			wp_cache_add( $action, $action_id, 'ticket_actions' );
		}

		return $action_id;
	}

	/**
	 * @internal
	 */
	function _insert_action( $action ) {
		global $wpdb;

		if ( !$action_id = $this->_is_action( $action )) {
			$action = substr( preg_replace( '/[^a-zA-Z0-9\-_]/', '', $action ), 0, 64 );
			if( empty( $action ))
				return FALSE;

			if ( false === $wpdb->insert( $this->ticket_actions, array( 'action' => $action )) ){
				new WP_Error('db_insert_error', __('Could not insert new ticket_action into the database'), $wpdb->last_error);
				return FALSE;
			}
			$action_id = (int) $wpdb->insert_id;

			wp_cache_add( $action, $action_id, 'ticket_actions' );
		}

		return $action_id;
	}


	/**
	 * Activation hook. Sets up database tables.
	 * @internal
	 */
	function _activate() {
		global $wpdb;

		$charset_collate = '';
		if ( version_compare(mysql_get_server_info(), '4.1.0', '>=') ) {
			if ( ! empty($wpdb->charset) )
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty($wpdb->collate) )
				$charset_collate .= " COLLATE $wpdb->collate";
		}

		require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

		dbDelta("
			CREATE TABLE $this->tickets (
				ticket_id bigint(20) NOT NULL auto_increment,
				action_id int(11) NOT NULL,
				ticket varchar(32) NOT NULL,
				arg longtext NOT NULL,
				PRIMARY KEY  (ticket_id),
				UNIQUE KEY ticket_uniq (ticket),
				KEY ticket (ticket(1))
			) ENGINE=MyISAM $charset_collate
			");

		dbDelta("
			CREATE TABLE $this->ticket_actions (
				action_id int(11) NOT NULL auto_increment,
				action varchar(64) NOT NULL,
				PRIMARY KEY  (action_id),
				UNIQUE KEY action_uniq (action),
				KEY action (action(1))
			) ENGINE=MyISAM $charset_collate
			");
	}
}

// Single instance of wpTix (used instead of a singleton)
$wptix = new wpTix();
