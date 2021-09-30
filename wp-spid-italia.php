<?php
/*
Plugin Name: WP SPID Italia
Description: SPID - Sistema Pubblico di Identità Digitale
Author: Marco Milesi
Version: 2.1
Author URI: http://www.marcomilesi.com
*/

include( plugin_dir_path( __FILE__ ) . 'constants.php');
include( plugin_dir_path( __FILE__ ) . 'frontend-ui.php');

add_action( 'admin_menu', function() {
  add_submenu_page(
    'options-general.php',
    'SPID', 'SPID',
    'manage_options', 'spid_menu',
    function() { include( plugin_dir_path( __FILE__ ) . 'admin/settings.php'); spid_menu_func(); }
  );
} );

add_action( 'admin_init', function() {
    register_setting('spid_options', 'spid');
    $arrayatpv = get_plugin_data ( __FILE__ );
    $nuova_versione = $arrayatpv['Version'];
    if ( version_compare( get_option('spid_version'), $nuova_versione, '<')) {
      update_option( 'spid_version', $nuova_versione );
    }
});

include( plugin_dir_path( __FILE__ ) . 'user.php');

add_filter( 'plugin_action_links_'.plugin_basename( __FILE__ ), function( $links ) {
    $settings_link = '<a href="options-general.php?page=spid_menu">Impostazioni</a>';
    array_push( $links, $settings_link );
  	return $links;
} );

add_filter('wp_login_errors', function($errors) {
    if ( isset($_GET['SimpleSAML_Auth_State_exceptionId']) ) {
        $errors->add('access', 'Login SPID non riuscito. Riprova tra qualche istante.');
    } else if ( isset($_GET['spid']) && $_GET['spid'] ) {
        $errors->add('access', 'Non è stata trovata alcuna utenza associata all\'indirizzo email o codice fiscale di SPID');
    }
    return $errors;
} );
  
add_action( 'init', function() {
    if ( session_status() == PHP_SESSION_NONE ) {
        session_start();
    }

    if ( isset( $_GET['spid_metadata'] ) && $_GET['spid_metadata'] == spid_get_metadata_token()  ) {
		header( 'Content-type: text/xml' );
        $sp = spid_load();
        echo $sp->getSPMetadata();
        die();
    }
} );

add_shortcode( 'spid_login_button', function( $atts ) {
    wp_enqueue_style( 'spid-css', plugins_url( 'css/spid-sp-access-button.min.css', __FILE__ ), false );
    wp_enqueue_script( 'spid-js', plugins_url( 'js/spid-sp-access-button.min.js', __FILE__ ), array( 'jquery' )  );

    $button = '';

    $button .= spid_get_login_button();

    return $button;
} );


add_action( 'login_form', function() {

    if ( !is_spid_enabled() ) {
        return;
    }

    $site_name = get_bloginfo( 'name' );
		if ( ! $site_name ) {
			$site_name = get_bloginfo( 'url' );
		}

		$display_name = ! empty( $_COOKIE[ 'spid_sso_wpcom_name_' . COOKIEHASH ] )
			? $_COOKIE[ 'spid_sso_wpcom_name_' . COOKIEHASH ]
			: false;
		$gravatar = ! empty( $_COOKIE[ 'spid_sso_wpcom_gravatar_' . COOKIEHASH ] )
			? $_COOKIE[ 'spid_sso_wpcom_gravatar_' . COOKIEHASH ]
			: false;

		?>
		<div id="spid-sso-wrap">
			<?php

				if ( $display_name && $gravatar ) : ?>
				<div id="spid-sso-wrap__user">
					<img width="72" height="72" src="<?php echo esc_html( $gravatar ); ?>" />

					<h2>
						<?php
							echo wp_kses(
								sprintf( __( 'Log in as <span>%s</span>', 'spid' ), esc_html( $display_name ) ),
								array( 'span' => true )
							);
						?>
					</h2>
				</div>

			<?php endif; ?>


        <div id="spid-sso-wrap__action">
            <p>
                <div style="text-align:center;margin:40px 0;">
                    <?php echo spid_get_idp_list(); ?>
                </div>
                
                <div class="spid-sso-or"><span><?php esc_html_e( apply_filters( 'spid_filter_login_or_after', __( 'Oppure', 'spid' ) ) ); ?></span></div>
            </p>
        </div>

        <div class="spid-sso-or spid-sso-toggle default">
            <span><?php esc_html_e( apply_filters( 'spid_filter_login_or_pre', __( 'Oppure', 'spid' ) ) ); ?></span>
        </div>
        

        <a href="<?php echo esc_url( add_query_arg( 'spid-sso-show-default-form', '1' ) ); ?>" class="spid-sso-toggle wpcom">
            <?php esc_html_e( apply_filters( 'spid_filter_loginbutton_footer', __( 'Log in with username and password', 'spid' ) ) ); ?>
        </a>
        <div class="spid-sso-toggle default">
            <?php echo spid_get_loginform_button(); ?>
        </div>
    </div>
<?php
} );

function tp_custom_logout() {
    if ( isset( $_GET['LO'] ) ) {
        $sp = spid_load();
        echo $sp->isAuthenticated();
        echo '<hr>';
        echo $sp->logout( 0, get_site_url() . '/wp-login.php?spid_sso=out', false );
        die();
    }
    if ( isset( $_GET['LOA'] ) ) {
        $sp = spid_load();
        $sp->logout( 0, get_site_url() . '/wp-login.php?spid_sso=out' );
        die();
    }
}

add_action( 'template_redirect', 'tp_custom_logout' );

add_filter( 'logout_url', function( $logout_url ) {
    $sp = spid_load();
    if ( $sp->isAuthenticated() ) {
        return get_site_url() . '/wp-login.php?spid_sso=out';
    }
    return $logout_url;
}, 10, 2 );

function spid_get_metadata_url() {
    return add_query_arg( 'spid_metadata', spid_get_metadata_token(), get_home_url() );
}

function spid_get_metadata_token() {
    //delete_option( 'spid_metadata_token' );
    $token = get_option( 'spid_metadata_token');
    if ( !$token ) {
        update_option( 'spid_metadata_token', substr(str_shuffle(str_repeat($x='0123456789-abcdefghijklmnopqrstuvwxyz', ceil( 15 / strlen($x)) )), 1, 15 ) );
        $token = get_option( 'spid_metadata_token');
    }
    return $token;
}

add_filter( 'login_message', function( $message ) {
    
    $internal_debug = false;
    $spid_debug = ( WP_DEBUG === true ) || $internal_debug;

    if ( $internal_debug ) {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
    }

    try {
        $sp = spid_load();
        $sp->isAuthenticated();
    } catch ( Exception  $e) {

        if ( $internal_debug ) {
            echo '<br><br><pre><small style="color:darkred;">'.$e->getMessage().'</small></pre>';
        }

        function spid_errors( $errorMsg2 ){
            $xmlString = isset($_GET['SAMLResponse']) ?
            gzinflate(base64_decode($_GET['SAMLResponse'])) :
            base64_decode($_POST['SAMLResponse']);
            $xmlResp = new \DOMDocument();
            $xmlResp->loadXML($xmlString);
            if ( $xmlResp->textContent ) {
                switch ( $xmlResp->textContent ) {
                    case stripos( $xmlResp->textContent, 'nr19') !== false:
                        return '<b>SPID errore 19</b> - Ripetuta sottomissione di credenziali errate';
                    case stripos( $xmlResp->textContent, 'nr20') !== false:
                        return '<b>SPID errore 20</b> - Utente privo di credenziali compatibili con il livello richiesto dal fornitore del servizio';
                    case stripos( $xmlResp->textContent, 'nr21') !== false:
                        return '<b>SPID errore 21</b> - Timeout';
                    case stripos( $xmlResp->textContent, 'nr22') !== false:
                        return '<b>SPID errore 22</b> - Utente nega il consenso all\'invio di dati al SP in caso di sessione vigente';
                    case stripos( $xmlResp->textContent, 'nr23') !== false:
                        return '<b>SPID errore 23</b> - Credenziali sospese o revocate';
                    case stripos( $xmlResp->textContent, 'nr25') !== false:
                        return '<b>SPID errore 25</b> - Processo di autenticazione annullato dall\'utente';
                    default: 
                        return 'Si è verificato un errore durante l\'accesso SPID. Contattare l\'amministratore per maggiori informazioni.';
                }
            }
        }
        
        add_filter( 'login_errors', 'spid_errors' );
        return;
    }

    if ( $internal_debug ) {
        echo '<div class="login"><form>';
        echo '<b>SPID Debug</b><br>';
        echo '<small>';
        echo '<br>Auth state: '.( $sp->isAuthenticated() ? 'authenticated' : 'not authenticated' );
        echo '<br>idpEntityId: '. ( isset( $_SESSION['idpEntityId'] ) ? $_SESSION['idpEntityId'] : '(not set)' );
        echo '</small>';
        echo '</form></div>';
    }

    if ( isset( $_GET['spid_sso'] ) && $_GET['spid_sso'] == 'out' ) {
        
        wp_clear_auth_cookie();
        remove_action('login_footer', 'wp_shake_js', 12);
        add_filter( 'login_errors', function() { return 'Disconnesso da SPID'; } );
        $sp->logout( 0, get_site_url() . '/wp-login.php?spid_sso=out' );
        //$sp->logout( 0, wp_logout_url() );
    } else if (isset($_POST) && isset($_POST['selected_idp'])) {
        $idp = $_POST['selected_idp'];
    } else if ( isset( $_GET['spid_sso'] ) && $_GET['spid_sso'] == 'in' ) {
        
        if ( is_user_logged_in() ) {
            wp_logout();
        }

        if ( isset( $_GET['spid_idp'] ) && $_GET['spid_idp'] != '' ) {
            if ( $sp->isAuthenticated() ) {
                session_destroy();
                #$sp->logout( 0, get_site_url() . '/wp-login.php?spid_sso=out&' );
            }
            $assertId = 0; // index of assertion consumer service as per the SP metadata (sp_assertionconsumerservice in settings array)
            $attrId = 0; // index of attribute consuming service as per the SP metadata (sp_attributeconsumingservice in settings array)
            $sp->login( 'idp_'.$_GET['spid_idp'], $assertId, $attrId); // Generate the login URL and redirect to the IdP login page
        } else if ( $sp->isAuthenticated() ) {
            $attributes = $sp->getAttributes();
            $name = $attributes['email'][0];    
            $user = get_user_by( 'email', $attributes['email'] );
            $cf = str_replace( 'TINIT-', '', $attributes['fiscalNumber']);
            
            if ( empty( $user ) ) {
                $users = get_users(
                    array(
                        'meta_key' => 'codice_fiscale',
                        'meta_value' => $cf,
                        'number' => 1,
                        'count_total' => false,
                    )
                );
                if ( !empty( $user ) ) {
                    $user = reset( $users );
                }
            }
            if ( !is_wp_error( $user ) && !empty( $user ) ) {
                
                if ( isset( $attributes['name'] ) ) {
                    update_user_meta( $user->ID, 'first_name', ucwords( strtolower( $attributes['name'] ) ) );
                }
                if ( isset( $attributes['familyName'] ) ) {
                    update_user_meta( $user->ID, 'last_name', ucwords( strtolower( $attributes['familyName'] ) ) );
                }
				update_user_meta( $user->ID, 'spid_attributes', $attributes);
                update_user_meta( $user->ID, 'codice_fiscale', $cf);
                wp_clear_auth_cookie();
                wp_set_current_user ( $user->ID );
                wp_set_auth_cookie  ( $user->ID );
            
                wp_safe_redirect( user_admin_url() );
                exit();
            } else {
                #$sp->logout( 0, add_query_arg( 'spid', 'nouser', wp_login_url() ) );
                //$sp->logout( add_query_arg( 'spid', 'nouser', wp_login_url() ) );
                echo '<img src="'.plugin_dir_url( __FILE__ ). '/img/spid.jpg" width="100%" />';
                echo '<style>body { background-color: #0066cb; }</style>';
                echo '<p style="color:#fff;font-size:1.2em;text-align:center;">';
                $attributes = $sp->getAttributes();
                echo 'Gentile '.$attributes['name'].',<br>il tuo account non è abilitato su questo sito.';
                //echo '<br><br><a class="button button-secondary button-hero" href="'.esc_url( wp_login_url() ).'" alt="Accedi">Accedi</a>';
                echo '<br><br><a class="button button-secondary button-large" href="'.esc_url( get_site_url() . '/wp-login.php?spid_sso=out' ).'" alt="Logout">Disconnetti SPID</a>';
                echo '</p>';
                die();
                return '<p class="error">' . __( 'SPID | You don\'t have an account on this site', 'wp_spid_italia' ) . '</p>';
                #exit();
            }
        }

    }
});


 
add_action('wp_enqueue_scripts', function(){
    wp_dequeue_style('spid-css');
    wp_dequeue_style('spid-js');
} );

add_action( 'login_enqueue_scripts', function() {
    wp_enqueue_style( 'spid-css', plugins_url( 'css/spid-sp-access-button.min.css', __FILE__ ), false );
    wp_enqueue_script( 'spid-js-button', plugins_url( 'js/spid-sp-access-button.min.js', __FILE__ ), array( 'jquery' )  );
    wp_enqueue_script( 'spid-js-loginform', plugins_url( 'js/spid-sp-loginform.js', __FILE__ ), array( 'jquery' )  );
}, 1 );

function is_spid_enabled() {
    return spid_option('enabled');
}

function spid_load() {
    
    if ( !is_spid_enabled() ) {
        return false;
    }

    if ( !is_dir( SPID__PERM_DIR ) ) {
        mkdir( SPID__PERM_DIR );
    }

    if ( !is_dir( SPID__CERT_DIR ) ) {
        mkdir( SPID__CERT_DIR );
    }


    require_once( SPID__LIB_DIR . 'vendor/autoload.php' );

    // ["name", "fiscalNumber", "email", "spidCode", "familyName", "placeOfBirth", "countyOfBirth", "dateOfBirth", "gender", "mobilePhone", "address"]

    return new Italia\Spid\Sp(
        array(
            'sp_entityid' => get_site_url(),
            'sp_key_file' => SPID__CERT_DIR.'sp.key',
            'sp_cert_file' => SPID__CERT_DIR.'sp.crt',
            'sp_comparison' => 'minimum', // one of: "exact", "minimum", "better" or "maximum"
            'sp_assertionconsumerservice' => [
                get_site_url() . '/wp-login.php?spid_sso=in', // Servizio standard
            ],
			'sp_singlelogoutservice'       => [ [ get_site_url() . '/wp-login.php?spid_sso=out', '' ] ],
            'sp_org_name' => spid_option( 'sp_org_name' ),
            'sp_org_display_name' => spid_option( 'sp_org_display_name' ),
            'sp_contact_ipa_code' => spid_option( 'sp_contact_ipa_code' ),
            //'sp_contact_fiscal_code' => spid_option( 'sp_contact_fiscal_code' ), // Deprecated - Avviso 29
            'sp_contact_email' => spid_option( 'sp_contact_email' ),
            'sp_contact_phone' => spid_option( 'sp_contact_phone' ),
            'sp_key_cert_values' => [ // Optional: remove this if you want to generate .key & .crt files manually
                'countryName' => spid_option( 'countryName' ),
                'stateOrProvinceName' => spid_option( 'stateOrProvinceName' ),
                'localityName' => spid_option( 'localityName' ),
                'commonName' => spid_option( 'commonName' ),
                'emailAddress' => spid_option( 'emailAddress' ),
            ],
            'idp_metadata_folder' => plugin_dir_path( __FILE__ ) . 'metadata/',
            'sp_attributeconsumingservice' => [ apply_filters( 'spid_filter_sp_attributeconsumingservice', [ "name", "familyName", "fiscalNumber", "email" ] ) ]
        ), null, true
    );
}

function spid_option($name) {
	$options = get_option('spid');
	if (isset($options[$name])) {
		return $options[$name];
	}
	return false;
}

if ( ! function_exists( 'wsi_fs' ) ) {
    // Create a helper function for easy SDK access.
    function wsi_fs() {
        global $wsi_fs;

        if ( ! isset( $wsi_fs ) ) {
            // Include Freemius SDK.
            require_once dirname(__FILE__) . '/freemius/start.php';

            $wsi_fs = fs_dynamic_init( array(
                'id'                  => '7763',
                'slug'                => 'wp-spid-italia',
                'type'                => 'plugin',
                'public_key'          => 'pk_60022b74a2ac02d5ea215998e8671',
                'is_premium'          => true,
                // If your plugin is a serviceware, set this option to false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'menu'                => array(
                    'slug'           => 'spid_menu',
                    'support'        => false,
                    'parent'         => array(
                        'slug' => 'options-general.php',
                    ),
                ),
                // Set the SDK to work in a sandbox mode (for development & testing).
                // IMPORTANT: MAKE SURE TO REMOVE SECRET KEY BEFORE DEPLOYMENT.
                'secret_key'          => 'sk_E0FP^f%CDVXJ)8K3XAJBruh;{Lhy8',
            ) );
        }

        return $wsi_fs;
    }

    // Init Freemius.
    wsi_fs();
    // Signal that SDK was initiated.
    do_action( 'wsi_fs_loaded' );
}

?>
