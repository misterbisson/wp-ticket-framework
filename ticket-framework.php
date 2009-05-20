<?php
/*
Plugin Name: WordPress Ticket Framework
Plugin URI: http://wordpress.org/extend/plugins/wp-ticket-framework/
Description: A framework for managing actions over time.
Version: 0.0
Author: Casey Bisson
Author URI: http://maisonbisson.com/
*/

class wpTix {

	var $url_base = 'do';
	var $query_var = 'do';

	function wpTix(){
		$this->__construct();
	}

	function __construct(){
		global $wpdb;

		$this->tickets = $wpdb->prefix . 'tickets';
		$this->ticket_actions = $wpdb->prefix . 'ticket_actions';

		add_action( 'init', array( &$this, 'init' ));
		add_action( 'parse_query', array( &$this, 'parse_query' ), 1 );
		add_action( 'did_ticket', array( &$this, 'did_ticket' ), 11 );
//		add_action( 'template_redirect', array( &$this, 'template_redirect' ), 11 );

		register_activation_hook( __FILE__, array( &$this, '_activate' ));
	}

	function init(){
		// add the rewrite rules
		add_rewrite_tag( '%'. $this->query_var .'%', '[^/]+' );
		add_rewrite_rule( $this->query_var .'/([^/]+)' , 'index.php?'. $this->query_var .'=$matches[1]', 'top' );
	}

	function parse_query( $query ){
		if( !empty( $query->query_vars[ $this->query_var ] ))
			$this->do_ticket( $query->query_vars[ $this->query_var ] );
	}

	function clean_up_after( $yes = TRUE ){
		if( $yes )
			add_action( 'did_ticket', array( &$this, 'did_ticket' ), 11 );
		else
			remove_action( 'did_ticket', array( &$this, 'did_ticket' ), 11 );
	}

	function template_redirect(){
		if( $template = get_page_template() )
			include( $template );
			die();
	}

	function get_url( $ticket_name ){
		global $wp_rewrite;

		if ( empty( $wp_rewrite->permalink_structure ))
			return( get_settings( 'siteurl' ) .'/?'. $query_var .'='. urlencode( $ticket_name ));
		else
			return( get_settings( 'siteurl' ) .'/'. $this->url_base .'/'. urlencode( $ticket_name ));
	}



	function is_ticket( $ticket_name ){
		global $wpdb;

		$ticket_name = substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $ticket_name ), 0, 32 );
		if( empty( $ticket_name ))
			return( FALSE );


		if ( !$ticket = wp_cache_get( $ticket_name, 'tickets' )) {
			$ticket = $wpdb->get_row( $wpdb->prepare("SELECT t.ticket_id, t.ticket, a.action, t.arg FROM ( SELECT * FROM $this->tickets t USE INDEX (ticket) WHERE ticket = %s LIMIT 1 ) t JOIN $this->ticket_actions a ON a.action_id = t.action_id LIMIT 1", $ticket_name) );

			wp_cache_add( $ticket_name, $ticket, 'tickets' );
		}

		if( ! $ticket )
			return( FALSE );

		$ticket->arg = maybe_unserialize( $ticket->arg );
		$ticket->url = $this->get_url( $ticket->ticket );

		return( $ticket );
	}

	function register_ticket( $action, $ticket_name, $arg = '' ){
		global $wpdb;

		$ticket['action_id'] = $this->_insert_action( $action );
		if( !$ticket['action_id'] )
			return( FALSE );

		$ticket['ticket'] = substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $ticket_name ), 0, 32 );
		if( empty( $ticket['ticket'] ))
			return( FALSE );

		$ticket['arg'] = maybe_serialize( $arg );

		if ( false === $wpdb->insert( $this->tickets, $ticket) ){
			new WP_Error('db_insert_error', __('Could not insert new ticket_action into the database'), $wpdb->last_error);
			return( FALSE );
		}

		wp_cache_add( $ticket_name, $ticket, 'tickets' );

		return( $this->is_ticket( $ticket_name ));
	}

	function do_ticket( $ticket_name ){
		global $wpdb;

		$ticket = $this->is_ticket( $ticket_name );
		if( ! $ticket )
			die( wp_redirect( get_settings( 'siteurl' ), '301'));

		// do the specified action
		do_action( $ticket->action, $ticket->arg, $ticket );

		// clean up after doing the ticket
		do_action( 'did_ticket', $ticket );
	}

	function did_ticket( $ticket ){
		$this->delete_ticket( $ticket->ticket );
		die( wp_redirect( get_settings( 'siteurl' ), '301'));
	}

	function delete_ticket( $ticket_name ){
		global $wpdb;

		$ticket = $this->is_ticket( $ticket_name );
		if( ! $ticket )
			return( FALSE );

		return( $wpdb->query( $wpdb->prepare( "DELETE FROM $this->tickets WHERE ticket_id = %d", $ticket->ticket_id ) ));
	}




	function generate_md5() {
		while( TRUE ){
			$ticket_name = md5( uniqid( rand(), true ));
			if( ! $this->is_ticket( $ticket_name ))
				return( $ticket_name );
		}
	}

	function generate_string( $len = 5, $alphabet = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' ){
		while( TRUE ){
			$ticket_name = $this->_generate_string();
			if( ! $this->is_ticket( $ticket_name ))
				return( $ticket_name );
		}
	}

	function _generate_string( $len = 5, $alphabet = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' ){
		$key = '';
		for( $i=0; $i < $len; $i++ )
			$key .= $alphabet[ rand( 0, ( strlen( $alphabet )) -1 ) ];

		return( $key );
	}




	function _is_action( $action ) {
		global $wpdb;

		$action = substr( preg_replace( '/[^a-zA-Z0-9\-_]/', '', $action ), 0, 64 );
		if( empty( $action ))
			return( FALSE );

		if ( !$action_id = wp_cache_get( $action, 'ticket_actions' )) {
			$action_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT action_id FROM $this->ticket_actions WHERE action = %s", $action ));

			wp_cache_add( $action, $action_id, 'ticket_actions' );
		}

		return( $action_id );
	}
	
	function _insert_action( $action ) {
		global $wpdb;

		if ( !$action_id = $this->_is_action( $action )) {
			$action = substr( preg_replace( '/[^a-zA-Z0-9\-_]/', '', $action ), 0, 64 );
			if( empty( $action ))
				return( FALSE );

			if ( false === $wpdb->insert( $this->ticket_actions, array( 'action' => $action )) ){
				new WP_Error('db_insert_error', __('Could not insert new ticket_action into the database'), $wpdb->last_error);
				return( FALSE );
			}
			$action_id = (int) $wpdb->insert_id;
			
			wp_cache_add( $action, $action_id, 'ticket_actions' );
		}

		return( $action_id );
	}



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

$wptix = new wpTix();