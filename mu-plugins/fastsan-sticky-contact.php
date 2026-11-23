<?php
/**
 * Plugin Name: Fastsan Sticky Contact Bar
 * Description: Floating contact bar (telefon, e-post, bli uppringd) for fastsan.se. Replaces the KK247 bar (aib-sticky-contact) which carried korkort247.se contact data and routed leads to the driving school.
 * Version: 1.3.0
 * Author: Ai Brick AB (A)
 *
 * CHANGELOG
 * 1.1.0 (2026-10-20, C) - Bli uppringd in i leadkedjan (B delta-audit C1, agar-GO).
 *                      Servern sparar via fastsan_lead_insert (source=callback) nar
 *                      pipelinen finns -> samma mejl med statusknappar, samma paminnelser,
 *                      samma tabell som offert- och Facebook-leads. Egen wp_mail bara som
 *                      reserv utan pipeline. Klienten visar Tack bara pa bekraftat svar,
 *                      annars felrad med telefonnummer; vid bekraftat svar skickas
 *                      generate_lead (GA4, form_id callback) och Lead (Meta, typeof-vakt).
 * 1.0.3 (2026-08-23, A) - Desktop-position ankrad: right 18% -> max(12px,
 *                      calc(50% - 720px)) = innehallsspaltens hogerkant.
 *                      Agarbeslut: lite mer centrerad, aldrig over texten.
 * 1.0.2 (2026-08-21, C) - Emoji-ikoner ersatta med inline-SVG (currentColor) —
 *                      emoji renderas i OS-farger och gav gra lur mot navy.
 *                      Desktop-position: right 20px -> 18%, bottom 20px -> 28px
 *                      (agarbeslut: narmare center, inte helt center).
 * 1.0.1 (2026-08-20) - Mottagaradress bytt till daniel@fastsan.se (agarbeslut).
 *                      Galler bade mailto-lanken i baren och callback-mailet.
 * 1.0.0 (2026-08-20) - Initial release. Fastsan-egna kontaktuppgifter,
 *                      egen AJAX-handler (fastsan_callback_request) som
 *                      mailar Fastsan och loggar i fastsan_callback_log.
 *                      Ingen elev-CPT-koppling, ingen SQL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'FASTSAN_STICKY_CONTACT_LOADED' ) ) {
	return;
}
define( 'FASTSAN_STICKY_CONTACT_LOADED', true );

define( 'FASTSAN_SB_PHONE_DISPLAY', '070-142 46 39' );
define( 'FASTSAN_SB_PHONE_TEL', '+46701424639' );
define( 'FASTSAN_SB_EMAIL', 'daniel@fastsan.se' );

/**
 * v1.3.0 (2026-11-23): stilen skrivs i <head> i stället för mitt i sidan. En <style> i body är inte giltig HTML
 * (W3C-kontrollen, QA-K punkt 6). Samma regler som förut.
 */
function fastsan_sb_skip() {
	if ( is_admin() ) {
		return true;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	foreach ( array( '/wp-login', '/wp-admin' ) as $s ) {
		if ( false !== strpos( $uri, $s ) ) {
			return true;
		}
	}
	return false;
}

add_action( 'wp_head', function () {
	if ( fastsan_sb_skip() ) {
		return;
	}
	?>
<style id="fastsan-sticky-contact">
.fs-sticky-bar{position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#15324a;display:flex;justify-content:center;align-items:stretch;box-shadow:0 -2px 12px rgba(0,0,0,.15);font-family:inherit;transition:transform .3s ease}
.fs-sticky-bar.fs-hidden{transform:translateY(100%)}
.fs-sticky-bar a{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10px 6px 12px;color:#fff;text-decoration:none;font-size:clamp(.68rem,2.9vw,.78rem);font-weight:500;gap:4px;border-right:1px solid rgba(255,255,255,.12);transition:background .2s;min-width:0}
.fs-sticky-bar a:last-child{border-right:none}
/* Telefonnumret är den längsta texten: den kolumnen får mer bredd, så att numret inte klipps ("Ring: 070-142 …", mätt 2026-11-23). */
.fs-sticky-bar a:first-child{flex:1.45}
.fs-sticky-bar a span:last-child{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.fs-sticky-bar a:hover,.fs-sticky-bar a:focus{background:rgba(255,255,255,.1)}
.fs-sticky-bar .fs-sb-icon{display:inline-flex;line-height:1}
.fs-sticky-bar .fs-sb-icon svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.fs-sticky-bar .fs-sb-callback{background:#fff;color:#15324a;font-weight:600}
.fs-sticky-bar .fs-sb-callback:hover,.fs-sticky-bar .fs-sb-callback:focus{background:#eef2f6}
@media (min-width:768px){.fs-sticky-bar{left:auto;right:max(12px,calc(50% - 720px));bottom:28px;width:360px;border-radius:12px;overflow:hidden}.fs-sticky-bar a{font-size:.78rem}}
/* Mobil: fältet ligger kant i kant med nederkanten. Sidan får lika mycket luft som fältet är högt, så att sista raden och fokus aldrig hamnar bakom det. Cookieknappen lyfts ovanför fältet. */
@media (max-width:767px){body{padding-bottom:calc(66px + env(safe-area-inset-bottom,0px))}.fs-sticky-bar{padding-bottom:env(safe-area-inset-bottom,0px)}.cky-btn-revisit-wrapper{bottom:calc(78px + env(safe-area-inset-bottom,0px))!important}}
@media (min-width:768px){.cky-consent-container ~ .fs-sticky-bar{bottom:80px}}
html{scroll-padding-bottom:7rem}
.fs-cb-overlay{display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:20px}
.fs-cb-overlay.fs-cb-open{display:flex}
.fs-cb-modal{background:#fff;border-radius:14px;max-width:400px;width:100%;padding:32px 28px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.fs-cb-modal h3{margin:0 0 6px;font-size:1.3rem;color:#15324a}
.fs-cb-modal .fs-cb-sub{color:#667085;font-size:.9rem;margin:0 0 20px}
.fs-cb-modal label{display:block;font-size:.85rem;font-weight:600;color:#15324a;margin-bottom:5px}
.fs-cb-modal input,.fs-cb-modal select{width:100%;padding:10px 12px;border:1px solid #d0d5dd;border-radius:8px;font-size:1rem;margin-bottom:14px;box-sizing:border-box}
.fs-cb-modal input:focus,.fs-cb-modal select:focus{outline:none;border-color:#15324a;box-shadow:0 0 0 3px rgba(21,50,74,.15)}
.fs-cb-modal button[type="submit"]{width:100%;padding:12px;background:#15324a;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;transition:background .2s}
.fs-cb-modal button[type="submit"]:hover{background:#0e2235}
.fs-cb-modal .fs-cb-close{position:absolute;top:12px;right:14px;background:none;border:none;font-size:1.4rem;cursor:pointer;color:#667085;padding:4px;line-height:1}
.fs-cb-modal .fs-cb-success{display:none;text-align:center;padding:20px 0}
.fs-cb-modal .fs-cb-error{display:none;color:#b3261e;font-size:14px;margin:10px 0 0}
.fs-cb-modal button[type=submit][disabled]{opacity:.6;cursor:wait}
.fs-cb-modal .fs-cb-success h3{color:#1c7c3a;margin-bottom:8px}
</style>
	<?php
}, 30 );

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	foreach ( array( '/wp-login', '/wp-admin' ) as $s ) {
		if ( false !== strpos( $uri, $s ) ) {
			return;
		}
	}
	$tel   = FASTSAN_SB_PHONE_TEL;
	$disp  = FASTSAN_SB_PHONE_DISPLAY;
	$mail  = FASTSAN_SB_EMAIL;
	?>
<div class="fs-sticky-bar" id="fsStickyBar">
	<a href="tel:<?php echo esc_attr( $tel ); ?>" aria-label="Ring <?php echo esc_attr( $disp ); ?>">
		<span class="fs-sb-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
		<span>Ring: <?php echo esc_html( $disp ); ?></span>
	</a>
	<a href="mailto:<?php echo esc_attr( $mail ); ?>" aria-label="Maila oss">
		<span class="fs-sb-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="22,6 12,13 2,6"/></svg></span>
		<span>Maila</span>
	</a>
	<a href="#" class="fs-sb-callback" id="fsCbOpen" role="button" aria-haspopup="dialog" aria-controls="fsCbOverlay">
		<span class="fs-sb-icon" aria-hidden="true"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><polyline points="17 7 23 7 23 13"/><line x1="16" y1="14" x2="23" y2="7"/><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
		<span>Bli uppringd</span>
	</a>
</div>

<div class="fs-cb-overlay" id="fsCbOverlay">
	<div class="fs-cb-modal" role="dialog" aria-modal="true" aria-labelledby="fsCbTitle">
		<button class="fs-cb-close" id="fsCbClose" aria-label="St&auml;ng">&times;</button>
		<div id="fsCbForm">
			<h3 id="fsCbTitle">Vi ringer upp dig</h3>
			<p class="fs-cb-sub">Ber&auml;tta n&auml;r det passar, s&aring; h&ouml;r vi av oss.</p>
			<form id="fsCallbackForm">
				<label for="fsCbName">Ditt namn</label>
				<input type="text" id="fsCbName" name="name" required autocomplete="given-name" placeholder="F&ouml;rnamn">
				<label for="fsCbPhone">Telefonnummer</label>
				<input type="tel" id="fsCbPhone" name="phone" required autocomplete="tel" placeholder="07X-XXX XX XX">
				<label for="fsCbWhen">N&auml;r vill du bli uppringd?</label>
				<select id="fsCbWhen" name="when">
					<option value="fm">F&ouml;rmiddag (08&ndash;12)</option>
					<option value="em">Eftermiddag (12&ndash;17)</option>
					<option value="kvall">Kv&auml;ll (17&ndash;20)</option>
				</select>
				<button type="submit">Ring upp mig</button>
			</form>
			<p class="fs-cb-error" id="fsCbError" role="alert">N&aring;got gick fel och f&ouml;rfr&aring;gan skickades inte. Ring oss direkt: <a href="tel:+46701424639">070-142 46 39</a>.</p>
		</div>
		<div class="fs-cb-success" id="fsCbSuccess">
			<h3><span aria-hidden="true">&#x2705;</span> Tack!</h3>
			<p>Vi ringer dig <strong id="fsCbWhenText">s&aring; snart vi kan</strong>.</p>
		</div>
	</div>
</div>

<script>
(function(){
	var bar=document.getElementById('fsStickyBar');
	var overlay=document.getElementById('fsCbOverlay');
	var openBtn=document.getElementById('fsCbOpen');
	var closeBtn=document.getElementById('fsCbClose');
	var form=document.getElementById('fsCallbackForm');
	var formDiv=document.getElementById('fsCbForm');
	var successDiv=document.getElementById('fsCbSuccess');
	var whenSel=document.getElementById('fsCbWhen');
	if(!bar||!overlay||!openBtn||!form){return;}
	function pickDefaultSlot(){var h=new Date().getHours();if(h>=17)return 'kvall';if(h>=12)return 'em';return 'fm';}
	if(whenSel){whenSel.value=pickDefaultSlot();}
	function openModal(){overlay.classList.add('fs-cb-open');document.body.style.overflow='hidden';var f=document.getElementById('fsCbName');if(f){f.focus();}}
	openBtn.addEventListener('click',function(e){e.preventDefault();openModal();});
	openBtn.addEventListener('keydown',function(e){if(e.key===' '||e.key==='Spacebar'){e.preventDefault();openModal();}});
	function closeModal(){var wasOpen=overlay.classList.contains('fs-cb-open');overlay.classList.remove('fs-cb-open');document.body.style.overflow='';if(wasOpen){openBtn.focus();}}
	overlay.addEventListener('keydown',function(e){
		if(e.key==='Escape'){e.preventDefault();closeModal();return;}
		if(e.key!=='Tab'){return;}
		var els=overlay.querySelectorAll('button,[href],input,select,textarea');var vis=[];for(var i=0;i<els.length;i++){if(els[i].offsetParent!==null&&!els[i].disabled){vis.push(els[i]);}}
		if(!vis.length){return;}
		if(e.shiftKey&&document.activeElement===vis[0]){e.preventDefault();vis[vis.length-1].focus();}
		else if(!e.shiftKey&&document.activeElement===vis[vis.length-1]){e.preventDefault();vis[0].focus();}
	});
	closeBtn.addEventListener('click',closeModal);
	overlay.addEventListener('click',function(e){if(e.target===overlay)closeModal();});
	form.addEventListener('submit',function(e){
		e.preventDefault();
		var name=document.getElementById('fsCbName').value.trim();
		var phone=document.getElementById('fsCbPhone').value.trim();
		var when=document.getElementById('fsCbWhen');
		var whenVal=when.value;
		var whenText=when.options[when.selectedIndex].text;
		if(!name||!phone)return;
		var data=new FormData();
		data.append('action','fastsan_callback_request');
		data.append('name',name);
		data.append('phone',phone);
		data.append('when',whenVal);
		data.append('when_text',whenText);
		data.append('page',window.location.pathname);
		var errEl=document.getElementById('fsCbError');var btn=form.querySelector('button[type=submit]');
		errEl.style.display='none';if(btn){btn.disabled=true;}
		fetch('/wp-admin/admin-ajax.php',{method:'POST',body:data,credentials:'same-origin'}).then(function(r){return r.ok?r.json():Promise.reject(new Error('HTTP '+r.status));}).then(function(j){
			if(!j||!j.success){throw new Error('nosuccess');}
			try{
				if(typeof window.gtag==='function'){window.gtag('event','generate_lead',{currency:'SEK',form_id:'callback',page_location:location.href});}
				if(typeof window.fbq==='function'){window.fbq('track','Lead',{content_name:location.pathname+'#callback'});}
			}catch(e){}
			document.getElementById('fsCbWhenText').textContent=whenText.toLowerCase();
			formDiv.style.display='none';
			successDiv.style.display='block';
			setTimeout(function(){closeModal();formDiv.style.display='';successDiv.style.display='none';form.reset();},4000);
		}).catch(function(){
			errEl.style.display='block';
		}).then(function(){if(btn){btn.disabled=false;}});
	});
	var lastScroll=0;
	window.addEventListener('scroll',function(){
		var st=window.pageYOffset||document.documentElement.scrollTop;
		if(st>lastScroll&&st>300){bar.classList.add('fs-hidden');}else{bar.classList.remove('fs-hidden');}
		lastScroll=st<=0?0:st;
	},{passive:true});
})();
</script>
	<?php
}, 99 );

add_action( 'wp_ajax_fastsan_callback_request', 'fastsan_handle_callback_request', 5 );
add_action( 'wp_ajax_nopriv_fastsan_callback_request', 'fastsan_handle_callback_request', 5 );

function fastsan_handle_callback_request() {
	$name      = sanitize_text_field( isset( $_POST['name'] ) ? $_POST['name'] : '' );
	$phone     = sanitize_text_field( isset( $_POST['phone'] ) ? $_POST['phone'] : '' );
	$when      = sanitize_text_field( isset( $_POST['when'] ) ? $_POST['when'] : 'fm' );
	$when_text = sanitize_text_field( isset( $_POST['when_text'] ) ? $_POST['when_text'] : '' );
	$page      = sanitize_text_field( isset( $_POST['page'] ) ? $_POST['page'] : '/' );

	if ( '' === $name || '' === $phone ) {
		wp_send_json_error( 'Missing fields', 400 );
	}

	$when_labels = array(
		'fm'    => "F\xC3\xB6rmiddag (08\xE2\x80\x9312)",
		'em'    => "Eftermiddag (12\xE2\x80\x9317)",
		'kvall' => "Kv\xC3\xA4ll (17\xE2\x80\x9320)",
	);
	$when_label = isset( $when_labels[ $when ] ) ? $when_labels[ $when ] : ( '' !== $when_text ? $when_text : $when );

	$subject = "[Fastsan] \xF0\x9F\x94\x94 Callback: {$name} ({$phone})";
	$body    = "Ny callback-f\xC3\xB6rfr\xC3\xA5gan fr\xC3\xA5n fastsan.se\n\n";
	$body   .= "Namn: {$name}\n";
	$body   .= "Telefon: {$phone}\n";
	$body   .= "N\xC3\xA4r: {$when_label}\n";
	$body   .= "Sida: {$page}\n";
	$body   .= 'Tid: ' . current_time( 'Y-m-d H:i:s' ) . "\n";

	// 1.1.0: in i leadkedjan nar pipelinen finns (samma mejl, statusknappar, paminnelser, tabell).
	$lead_id = 0;
	if ( function_exists( 'fastsan_lead_insert' ) ) {
		$lead_id = (int) fastsan_lead_insert( array(
			'name'               => $name,
			'phone'              => $phone,
			'contact_preference' => 'phone',
			'message'            => "Vill bli uppringd: {$when_label}\nSida: {$page}",
			'source'             => 'callback',
			'ip_address'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '',
			'user_agent'         => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_textarea_field( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'referer'            => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( $_SERVER['HTTP_REFERER'] ) : '',
		) );
	}
	if ( $lead_id <= 0 ) {
		// Reserv: pipelinen saknas eller insert misslyckades -> gamla vagen, mejlet gar fram anda.
		wp_mail( FASTSAN_SB_EMAIL, $subject, $body );
	}

	$log   = get_option( 'fastsan_callback_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = array(
		'name'  => $name,
		'phone' => $phone,
		'when'  => $when,
		'page'  => $page,
		'time'  => current_time( 'Y-m-d H:i:s' ),
	);
	if ( count( $log ) > 100 ) {
		$log = array_slice( $log, -100 );
	}
	update_option( 'fastsan_callback_log', $log, false );

	error_log( sprintf( '[AIB] Fastsan callback: %s / %s / %s', $name, $phone, $when ) );

	wp_send_json_success( array( 'status' => 'ok', 'lead_id' => $lead_id ) );
}
