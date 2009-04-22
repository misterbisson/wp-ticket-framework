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

	var $query_var = 'do';

	function __construct(){
		global $wpdb;

		$this->tickets = $wpdb->prefix . 'tickets';
		$this->ticket_actions = $wpdb->prefix . 'ticket_actions';

		add_action( 'init', array( &$this, 'init' ));
		add_action( 'parse_query', array( &$this, 'parse_query' ), 1 );
		add_action( 'did_ticket', array( &$this, 'did_ticket' ), 11 );
	}

	function wpTix(){
		return( $this->__construct() );
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

	function get_url( $ticket_name ){
	}


	function is_ticket( $ticket_name ){
		global $wpdb;

		$ticket_name = substr( sanitize_title_with_dashes( $ticket_name ), 0, 32 );
		if( empty( $ticket_name ))
			return( FALSE );

		$ticket = $wpdb->get_row( $wpdb->prepare("SELECT ticket_id, ticket, (SELECT action FROM $this->ticket_actions WHERE action_id = action_id) AS action, arg FROM $this->tickets WHERE ticket = %s LIMIT 1", $ticket_name) );

		if( ! $ticket )
			return( FALSE );

		$ticket->arg = maybe_unserialize( $ticket->arg );

		return( $ticket );
	}

	function register_ticket( $ticket_name, $action, $arg = '' ){
		global $wpdb;

		$ticket['action_id'] = $this->_insert_action( $action );
		if( !$ticket['action_id'] )
			return( FALSE );

		$ticket['ticket'] = substr( sanitize_title_with_dashes( $ticket_name ), 0, 32 );
		if( empty( $ticket['ticket'] ))
			return( FALSE );

		$ticket['arg'] = maybe_serialize( $arg );

		if ( false === $wpdb->insert( $this->tickets, $ticket) ){
			new WP_Error('db_insert_error', __('Could not insert new ticket_action into the database'), $wpdb->last_error);
			return( FALSE );
		}

		return( TRUE );
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
		return( md5( uniqid( rand(), true )));
	}

	function generate_string( $len = 5, $alphabet = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' ){

		$key = '';
		for( $i=0; $i < $len; $i++ )
			$key .= $alphabet[ rand( 0, ( strlen( $alphabet )) -1 ) ];

		return( $key );
	}




	function _is_action( $action ) {
		global $wpdb;

		$action = substr( sanitize_title_with_dashes( $action ), 0, 64 );
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
			$action = substr( sanitize_title_with_dashes( $action ), 0, 64 );
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



}
$wptix = new wpTix();