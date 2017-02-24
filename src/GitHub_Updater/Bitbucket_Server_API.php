<?php
/**
 * GitHub Updater
 *
 * @package   GitHub_Updater
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 */

/**
 * TODO:
 * - paging API support, for we're limited to Bitbucket Server default limit of 25
 * - personal repositories are not yet supported, using project based repositories
 **/

namespace Fragen\GitHub_Updater;

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Bitbucket_Server_API
 *
 * Get remote data from a self-hosted Bitbucket Server repo.
 * Assumes an owner == project_key
 *
 * @package Fragen\GitHub_Updater
 * @author  Andy Fragen
 * @author  Bjorn Wijers
 */
class Bitbucket_Server_API extends Bitbucket_API {

	/**
	 * Constructor.
	 *
	 * @param object $type
	 */
	public function __construct( $type ) {
		$this->type     = $type;
		$this->response = $this->get_repo_cache();

		$this->load_hooks();

		if ( ! isset( self::$options['bitbucket_enterprise_username'] ) ) {
			self::$options['bitbucket_enterprise_username'] = null;
		}
		if ( ! isset( self::$options['bitbucket_enterprise_password'] ) ) {
			self::$options['bitbucket_enterprise_password'] = null;
		}
		add_site_option( 'github_updater', self::$options );
	}

	/**
	 * Load hooks for Bitbucket authentication headers.
	 */
	public function load_hooks() {
		add_filter( 'http_request_args', array( &$this, 'maybe_authenticate_http' ), 5, 2 );
		add_filter( 'http_request_args', array( &$this, 'http_release_asset_auth' ), 15, 2 );
	}

	/**
	 * Remove hooks for Bitbucket authentication headers.
	 */
	public function remove_hooks() {
		remove_filter( 'http_request_args', array( &$this, 'maybe_authenticate_http' ) );
		remove_filter( 'http_request_args', array( &$this, 'http_release_asset_auth' ) );
		remove_filter( 'http_request_args', array( &$this, 'ajax_maybe_authenticate_http' ) );
	}

	/**
	 * Read the remote file and parse headers.
	 *
	 * @param $file
	 *
	 * @return bool
	 */
	public function get_remote_info( $file ) {
		$response = isset( $this->response[ $file ] ) ? $this->response[ $file ] : false;

		if ( ! $response ) {
			if ( empty( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}

			$path = '/1.0/projects/:owner/repos/:repo/browse/' . $file;
			$path = add_query_arg( 'at', $this->type->branch, $path );

			$response = $this->api( $path );

			if ( $response ) {
				$contents = $this->_recombine_response( $response );
				$response = $this->get_file_headers( $contents, $this->type->type );
				$this->set_repo_cache( $file, $response );
			}
		}

		if ( $this->validate_response( $response ) || ! is_array( $response ) ) {
			return false;
		}

		$response['dot_org'] = $this->get_dot_org_data();
		$this->set_file_info( $response );

		return true;
	}


	/**
	 * Combines separate text lines from API response
	 * into one string with \n line endings.
	 * Code relying on raw text can now parse it.
	 *
	 * @param object $response
	 *
	 * @return string combined lines of text returned by API
	 */
	private function _recombine_response( $response ) {
		$remote_info_file = '';
		if ( is_array( $response->lines ) ) {
			foreach ( $response->lines as $line ) {
				$remote_info_file .= $line->text . "\n";
			}
		}

		return $remote_info_file;
	}

	/**
	 * Get the remote info for tags.
	 *
	 * @return bool
	 */
	public function get_remote_tag() {
		$repo_type = $this->return_repo_type();
		$response  = isset( $this->response['tags'] ) ? $this->response['tags'] : false;

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo/tags' );

			if ( ! $response ||
			     ( isset( $response->size ) && $response->size < 1 ) ||
			     isset( $response->errors )
			) {
				$response          = new \stdClass();
				$response->message = 'No tags found';
			}

			if ( $response ) {
				$response = $this->parse_tag_response( $response );
				$this->set_repo_cache( 'tags', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->parse_tags( $response, $repo_type );

		return true;
	}

	/**
	 * Read the remote CHANGES.md file
	 *
	 * @param $changes
	 *
	 * @return bool
	 */
	public function get_remote_changes( $changes ) {
		$response = isset( $this->response['changes'] ) ? $this->response['changes'] : false;

		/*
		 * Set $response from local file if no update available.
		 */
		if ( ! $response && ! $this->can_update( $this->type ) ) {
			$response = new \stdClass();
			$content  = $this->get_local_info( $this->type, $changes );
			if ( $content ) {
				$response->data = $content;
				$this->set_repo_cache( 'changes', $response );
			} else {
				$response = false;
			}
		}

		if ( ! $response ) {
			if ( ! isset( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}

			// use a constructed url to fetch the raw file response
			// due to lack of file download option in Bitbucket Server
			$response = $this->_fetch_raw_file( $changes );

			if ( ! $response ) {
				$response          = new \stdClass();
				$response->message = 'No changelog found';
			}

			if ( $response ) {
				$response = wp_remote_retrieve_body( $response );
				$response = $this->parse_changelog_response( $response );
				$this->set_repo_cache( 'changes', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$parser    = new \Parsedown;
		$changelog = $parser->text( $response['changes'] );

		$this->type->sections['changelog'] = $changelog;

		return true;
	}

	/**
	 * Read and parse remote readme.txt.
	 *
	 * @return bool
	 */
	public function get_remote_readme() {
		if ( ! file_exists( $this->type->local_path . 'readme.txt' ) &&
		     ! file_exists( $this->type->local_path_extended . 'readme.txt' )
		) {
			return false;
		}

		$response = isset( $this->response['readme'] ) ? $this->response['readme'] : false;

		/*
		 * Set $response from local file if no update available.
		 */
		if ( ! $response && ! $this->can_update( $this->type ) ) {
			$response = new \stdClass();
			$content  = $this->get_local_info( $this->type, 'readme.txt' );
			if ( $content ) {
				$response->data = $content;
			} else {
				$response = false;
			}
		}

		if ( ! $response ) {
			if ( ! isset( $this->type->branch ) ) {
				$this->type->branch = 'master';
			}

			$response = $this->_fetch_raw_file( 'readme.txt' );

			if ( ! $response ) {
				$response          = new \stdClass();
				$response->message = 'No readme found';
			}

			if ( $response && isset( $response->data ) ) {
				$file     = $response->data;
				$parser   = new Readme_Parser( $file );
				$response = $parser->parse_data();
				$this->set_repo_cache( 'readme', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->set_readme_info( $response );

		return true;
	}


	/**
	 * The Bitbucket Server REST API does not support downloading files directly at the moment
	 * therefore we'll use this to construct urls to fetch the raw files ourselves.
	 *
	 * @param string $file filename
	 *
	 * @return bool|array false upon failure || return wp_remote_get() response array
	 **/
	private function _fetch_raw_file( $file ) {
		$file         = urlencode( $file );
		$download_url = implode( '/', array(
			$this->type->enterprise,
			'projects',
			$this->type->owner,
			'repos',
			$this->type->repo,
			'browse',
			$file,
		) );
		$download_url = add_query_arg( array( 'at' => $this->type->branch, 'raw' => '' ), $download_url );

		$response = wp_remote_get( esc_url_raw( $download_url ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return $response;
	}


	/**
	 * Read the repository meta from API
	 *
	 * @return bool
	 */
	public function get_repo_meta() {
		$response = isset( $this->response['meta'] ) ? $this->response['meta'] : false;

		if ( $this->exit_no_update( $response ) ) {
			return false;
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo' );

			if ( $response ) {
				$response = $this->parse_meta_response( $response );
				$this->set_repo_cache( 'meta', $response );
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->repo_meta = $response;
		$this->add_meta_repo_object();

		return true;
	}

	/**
	 * Create array of branches and download links as array.
	 *
	 * @return bool
	 */
	public function get_remote_branches() {
		$branches = array();
		$response = isset( $this->response['branches'] ) ? $this->response['branches'] : false;

		if ( $this->exit_no_update( $response, true ) ) {
			return false;
		}

		if ( ! $response ) {
			$response = $this->api( '/1.0/projects/:owner/repos/:repo/branches' );
			if ( $response ) {
				foreach ( $response as $branch => $api_response ) {
					$branches[ $branch ] = $this->construct_download_link( false, $branch );
				}
				$this->type->branches = $branches;
				$this->set_repo_cache( 'branches', $branches );

				return true;
			}
		}

		if ( $this->validate_response( $response ) ) {
			return false;
		}

		$this->type->branches = $response;

		return true;
	}

	/**
	 * Construct $this->type->download_link using Bitbucket API
	 *
	 * @param boolean $rollback      for theme rollback
	 * @param boolean $branch_switch for direct branch changing
	 *
	 * @return string $endpoint
	 */
	public function construct_download_link( $rollback = false, $branch_switch = false ) {

		/*
		 * Downloads requires the forked stash-archive plugin which enables
		 * subdirectory support using the prefix query argument
		 * see https://bitbucket.org/BjornW/stash-archive/src
		 * the jar-file directory contains a jar file for convenience so you don't have
		 * to install the Atlassian SDK
		 */
		$download_link_base = implode( '/', array(
			$this->type->enterprise,
			'plugins/servlet/archive/projects',
			$this->type->owner,
			'repos',
			$this->type->repo,
		) );

		$endpoint = '';

		/*
		 * add a prefix query argument to create a subdirectory with the same name
		 * as the repo, e.g. 'my-repo' becomes 'my-repo/'
		 */
		//$endpoint = add_query_arg( 'prefix', $this->type->repo . '/', $endpoint );

		if ( 'master' != $this->type->branch || empty( $this->type->tags ) ) {
			$endpoint = add_query_arg( 'at', $this->type->branch, $endpoint );
		} else {
			$endpoint = add_query_arg( 'at', $this->type->newest_tag, $endpoint );
		}

		if ( $branch_switch ) {
			$endpoint = add_query_arg( 'at', $branch_switch, $endpoint );
		}

		return $download_link_base . $endpoint;
	}

	/**
	 * Get/process Language Packs.
	 *
	 * @TODO Bitbucket Enterprise
	 *
	 * @param array $headers Array of headers of Language Pack.
	 *
	 * @return bool When invalid response.
	 */
	public function get_language_pack( $headers ) {
		$response = ! empty( $this->response['languages'] ) ? $this->response['languages'] : false;
		$type     = explode( '_', $this->type->type );

		if ( ! $response ) {
			$response = $this->api( '/1.0/repositories/' . $headers['owner'] . '/' . $headers['repo'] . '/src/master/language-pack.json' );

			if ( $this->validate_response( $response ) ) {
				return false;
			}

			if ( $response ) {
				$response = json_decode( $response->data );

				foreach ( $response as $locale ) {
					$package = array( 'https://bitbucket.org', $headers['owner'], $headers['repo'], 'raw/master' );
					$package = implode( '/', $package ) . $locale->package;

					$response->{$locale->language}->package = $package;
					$response->{$locale->language}->type    = $type[1];
					$response->{$locale->language}->version = $this->type->remote_version;
				}

				$this->set_repo_cache( 'languages', $response );
			}
		}
		$this->type->language_packs = $response;
	}

	/**
	 *
	 *


		}

	}

	/**
	 *
	 *
	 */
		}



	}

}
