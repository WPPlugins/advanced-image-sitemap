<?php
/*
 * Plugin Name: Advanced Image Sitemap
 * Plugin URI: http://www.makong.kiev.ua/plugins/ais
 * Description: Most advanced plugin for Image Sitemap generator up-to-date. Boost your website indexation in Google (and other) Search Engines.
 * Version: 1.1
 * Author: makong
 * Author URI: http://www.makong.kiev.ua
 * License: GPL2
 */
register_activation_hook( __FILE__, 'ais_install');
load_plugin_textdomain( 'ais', '', dirname( plugin_basename( __FILE__ ) ) . '/languages' );

/*actions*/
add_action('admin_menu', 'ais_admin_page');
add_action('wp_ajax_ais_generate', 'ajax_ais_generate');
add_action('wp_ajax_ais_remove', 'ajax_ais_remove');

function ais_install(){
    
    $ais_options = array(
        'sizes' => array(),
        'tags' => array(),
        'exclude' => array(
            'bysize' => array(
                'width' => 50,
                'height' => 50
            ),
            'byplug' => 'on'
        ),
        'date' => ''
    );
    
    add_option('ais_options', array());
}

function ais_admin_page(){
    
    global $hook, $ais_image_sizes;

    $hook = add_options_page('ais', 'AIS', 8, 'ais', 'ais_page');
    $ais_image_sizes = ais_get_image_sizes();
}

function ais_get_image_sizes( $size = '' ) {
    
    global $_wp_additional_image_sizes;
    $sizes = array();
    $get_intermediate_image_sizes = get_intermediate_image_sizes();
    foreach( $get_intermediate_image_sizes as $_size ) {
        if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
            $sizes[ $_size ]['width'] = get_option( $_size . '_size_w' );
            $sizes[ $_size ]['height'] = get_option( $_size . '_size_h' );
            $sizes[ $_size ]['crop'] = (bool) get_option( $_size . '_crop' );
        } elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
            $sizes[ $_size ] = array( 
                'width' => $_wp_additional_image_sizes[ $_size ]['width'],
                'height' => $_wp_additional_image_sizes[ $_size ]['height'],
                'crop' =>  $_wp_additional_image_sizes[ $_size ]['crop']
            );
        }
    }
    if ( $size ) {
        if( isset( $sizes[ $size ] ) ) {
            return $sizes[ $size ];
        } else {
            return false;
        }
    }
    return $sizes;
}

function ais_get_items(){
    
    global $wpdb;
    $ais_image_sizes = ais_get_image_sizes();
    $ais_options = get_option('ais_options');
    $pattern = '~https?://[^/\s]+/\S+\.(jpe?g|png|gif|[tg]iff?|svg)~i';
    $page_templates = ais_get_page_templates();
    $post_formats = array_merge(get_theme_support( 'post-formats' ), array(array('standard')));
    $items = array();
    
    $posts = $wpdb->get_results(
        " SELECT p.ID as img_id, p.guid as img_url, pm.post_id as post_id ".
        " FROM $wpdb->posts p INNER JOIN $wpdb->postmeta pm ".
        " ON (p.post_status = 'publish' or p.post_status = 'inherit') " . 
        " AND p.post_type = 'attachment' AND ( " .
            "(p.post_mime_type = 'image/jpg') or " .
            "(p.post_mime_type = 'image/gif') or " . 
            "(p.post_mime_type = 'image/jpeg') or " . 
            "(p.post_mime_type = 'image/png') or " .
            "(p.post_mime_type = 'image/svg+xml') or " .
            "(p.post_mime_type = 'image/tiff')) " . 
        " AND pm.meta_value = p.ID AND pm.meta_key = '_thumbnail_id'"
    );

    if(!empty($posts)){
        foreach($posts as $post) {
            $images_by_pt[$post->post_id] = $post->img_url;
        }
    }
    unset($posts);
    
    foreach(get_post_types(array('public' => true), 'names') as $pt){

        if('page' !== $pt){
            foreach( $post_formats as $pf ){

                if(!isset($examples[$pt][$pf[0]])){

                    if('standard' != $pf[0]) $in_query = "t.name = 'post-format-".$pf[0]."'";
                    else $in_query = "t.name NOT LIKE 'post-format'";

                    $p = $wpdb->get_results( "SELECT p.ID as pid FROM $wpdb->posts p"
                        . " INNER JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id"
                        . " INNER JOIN $wpdb->terms t ON $in_query AND tr.term_taxonomy_id = t.term_id"
                        . " WHERE p.post_type = '$pt' LIMIT 1");

                    /******/             

                    @preg_match_all($pattern, @file_get_contents(get_the_permalink($p[0]->pid)), $matches);

                    $examples[$pt][$pf[0]] = array_unique(array_map('trim', $matches[0]));

                    $search_key = @array_search($images_by_pt[$p[0]->pid], $examples[$pt][$pf[0]]);
                    unset($examples[$pt][$pf[0]][$search_key]);
                    $examples[$pt][$pf[0]]['full'] = '';

                    $info = @pathinfo($images_by_pt[$p[0]->pid]);
                    foreach($ais_image_sizes as $k => $size){
                        if(isset($ais_options['sizes'][$k]) and 'on' === $ais_options['sizes'][$k]){
                            $search_key = @array_search($info['dirname'].'/'.$info['filename'].'-'.$size['width'].'x'.$size['height'].'.'.$info['extension'], $examples[$pt][$pf[0]]);
                            unset($examples[$pt][$pf[0]][$search_key]);
                            $examples[$pt][$pf[0]][$size['width'].'x'.$size['height']] = '';
                        }
                    }

                    unset($p, $matches, $search_key);

                    /******/ 

                }
                else break;
            } 
        } else{
            foreach($page_templates as $tpl){

                if(!isset($examples[$pt][$tpl])){

                    $p = $wpdb->get_results( "SELECT p.ID as pid FROM $wpdb->posts p"
                        . " INNER JOIN $wpdb->postmeta pm ON pm.post_id = p.ID AND pm.meta_value = '$tpl'"
                        . " WHERE p.post_type = '$pt' LIMIT 1");

                    /******/             

                    @preg_match_all($pattern, @file_get_contents(get_the_permalink($p[0]->pid)), $matches);

                    $examples[$pt][$tpl] = array_unique(array_map('trim', $matches[0]));

                    $search_key = @array_search($images_by_pt[$p[0]->pid], $examples[$pt][$tpl]);
                    unset($examples[$pt][$tpl][$search_key]);
                    $examples[$pt][$tpl]['full'] = '';

                    $info = @pathinfo($images_by_pt[$p[0]->pid]);
                    foreach($ais_image_sizes as $k => $size){
                        if(isset($ais_options['sizes'][$k]) and 'on' === $ais_options['sizes'][$k]){
                            $search_key = @array_search($info['dirname'].'/'.$info['filename'].'-'.$size['width'].'x'.$size['height'].'.'.$info['extension'], $examples[$pt][$tpl]);
                            unset($examples[$pt][$tpl][$search_key]);
                            $examples[$pt][$tpl][$size['width'].'x'.$size['height']] = '';
                        }
                    }

                    unset($p, $matches, $search_key);

                    /******/ 
                }
                else break;
            }
        }

        $posts = $wpdb->get_results("SELECT ID, post_title FROM $wpdb->posts WHERE post_type ='$pt' AND post_status = 'publish'");

        if(!empty($posts)){
            
            foreach($posts as $post) {

                if('page' === $pt){
                    $by = (get_page_template_slug($post->ID)) ? get_page_template_slug($post->ID) : 'default';
                }else{
                    $by = (get_post_format( $post->ID )) ? get_post_format( $post->ID ) : 'standard';
                }

                $images = $examples[$pt][$by];

                if(isset($images_by_pt[$post->ID])){
                    $images['full'] = $images_by_pt[$post->ID];
                    $info = pathinfo($images_by_pt[$post->ID]);

                    foreach($ais_image_sizes as $k => $size){
                        if(isset($ais_options['sizes'][$k]) and 'on' === $ais_options['sizes'][$k]){
                            $images[$size['width'].'x'.$size['height']] =  $info['dirname'].'/'.$info['filename'].'-'.$size['width'].'x'.$size['height'].'.'.$info['extension'];
                        }
                    }
                }

                if(!empty($images)){
                    
                    $items[$post->ID] = array(
                        'link'   => get_the_permalink($post->ID),
                        'images' => array_filter($images)
                    );
                    unset($images);
                }
            }
        }
    }
    
    return $items;
}

function ais_get_xml(){
    
    $xml = '';
    $items = ais_get_items();
        
    if(!empty($items)){
        $ais_options = get_option('ais_options');
        
        $xml .= '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<!-- generated="'. date("d/m/Y H:i:s") .'" -->'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        
        foreach($items as $item){
            $xml .= "<url>\n<loc>\n" . $item['link'] . "</loc>\n";
            foreach($item['images'] as $k => $img){
                if($filename = ais_get_filename($img)){
                    $xml .= "<image:image>\n";
                    $xml .= "<image:loc>$img</image:loc>";
                    foreach(array_filter($ais_options['tags']) as $tname => $tvalue){
                        $xml .= "<image:$tname>" . str_replace('%NAME%', ucwords(str_replace(array('-', '_'), ' ', $filename)), $tvalue) . "</image:$tname>\n";
                    }
                    $xml .= "</image:image>\n";
                }
            }
            $xml .= "</url>\n";
        }
        $xml .= "\n</urlset>";
    }
    
    return $xml;
}

function ajax_ais_generate(){
    
    if(function_exists('current_user_can') && !current_user_can('manage_options') ) 
        wp_die();
    
    if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'ajax_ais_generate_nonce' ) )
        wp_die();
    
    $xml = ais_get_xml();
    if($xml){
        $ais_options = get_option('ais_options');
        $file = '%s/sitemap-image.xml';
        $sitemap_path = sprintf($file, $_SERVER["DOCUMENT_ROOT"]);
               
        if(ais_is_writable($_SERVER["DOCUMENT_ROOT"]) && ais_is_writable($sitemap_path)) {
            if(file_put_contents($sitemap_path, $xml)) {
                          
                $ais_options['date'] = date("d/m/Y H:i:s");
                update_option('ais_options', $ais_options);
                
            }else{
                wp_send_json_error( array( 'error' => __( 'Failure! Cannot save XML', 'ais' ) ) );
            }
        }else{
            wp_send_json_error( array( 'error' => __( 'Failure! Directory isn\'t writable', 'ais' ) ) );
        }
    }else{
        wp_send_json_error( array( 'error' => __( 'Failure! Cannot create XML', 'ais' ) ) );
    }
    
    exit();
}

function ais_get_page_templates(){
    
    global $wpdb;
   
    $templs = $wpdb->get_results("SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_page_template' ");
    
    foreach($templs as $templ){
        $data[] = $templ->meta_value;
    }
    
    return $data;
}

function ais_get_filename($img){
    
    $filename = null;
    $file['info'] = @pathinfo($img);
    $file['size'] = @getimagesize($img);

    $filename = $file['info']['filename'];

    if( $ais_options['exclude']['bysize']['width'] > $file['size'][0] ){
        $filename = null;
    }
    if( $ais_options['exclude']['bysize']['height'] > $file['size'][1] ){
        $filename = null;
    }
    if( 'on' === $ais_options['exclude']['byplug'] and strpos($file['info']['dirname'], 'plugins') ){
        $filename = null;
    }
    
    return $filename;
}

function ais_exclude_check($img, $exclude = array()){
    
    if($img){
        $img_info = @pathinfo($img);
        $img_size = @getimagesize($img);
        
        if( $exclude['bysize']['width'] > $img_size[0] ){
            return false;
        }
        
        if( $exclude['bysize']['height'] > $img_size[1] ){
            return false;
        }

        if( 'on' === $exclude['byplug'] and strpos($img_info['dirname'], 'plugins') ){
            return false;
        }
        
        return ucwords(str_replace(array('-', '_'), ' ', $img_info['filename']));
    }
    else return false;
}

function ais_xml_entities($xml) {
    return str_replace(array('&', '<', '>', '\'', '"'), array('&amp;', '&lt;', '&gt;', '&apos;', '&quot;'), $xml);
}

function ais_is_writable($filename) {
    
    if(!is_writable($filename)) {
        if(!@chmod($filename, 0666)) {
            $pathtofilename = dirname($filename);
            if(!is_writable($pathtofilename)) {
                if(!@chmod($pathtoffilename, 0666)) {
                    return false;
                }
            }
        }
    }
    
    return true;
}

function ais_last_modified(){
    
    $ais_options = get_option('ais_options');
    $file = '%s/sitemap-image.xml';
   
    if($ais_options['date'] && file_exists(sprintf($file, $_SERVER["DOCUMENT_ROOT"]))){
        
        printf( '%1$s %2$s <a href="%3$s" target="_blank">%3$s</a> <a href="#" id="remove_xml" title="%4$s">%4$s</a>', 
            __('Last modify', 'ais'), $ais_options['date'], sprintf($file, get_bloginfo('url')), __('Remove XML', 'ais')
        );
    }
}

function ajax_ais_remove(){
    
    if(function_exists('current_user_can') && !current_user_can('manage_options') ) 
        wp_die();
    
    if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'ajax_ais_remove_nonce' ) )
        wp_die();
        
    $ais_options = get_option('ais_options');
    $file = '%s/sitemap-image.xml';
    $sitemap_path = sprintf($file, $_SERVER["DOCUMENT_ROOT"]);
    
    if(unlink($sitemap_path)){
        unset($ais_options['date']);
        update_option('ais_options', $ais_options);
    }else{
        wp_send_json_error( array( 'error' => __( 'Failure! Cannot remove XML', 'ais' ) ) );
    }
    
    exit();
}

function ais_page(){
    
    global $hook, $ais_image_sizes;
    if($hook): 
        
        if(isset($_POST['ais_settings_btn'])){
            
            if(function_exists('current_user_can') && !current_user_can('manage_options') ) 
                wp_die();
            if (function_exists ('check_admin_referer') ) 
                check_admin_referer($_POST['action'].'_form');
                          
            update_option('ais_options', array(
                'sizes' => $_POST['sizes'],
                'tags' => array_map('sanitize_text_field', $_POST['tags']),
                'exclude' => array(
                    'bysize' => array(
                        'width' => absint($_POST['exclude']['bysize']['width']),
                        'height' => absint($_POST['exclude']['bysize']['height'])
                    ),
                    'byplug' => $_POST['exclude']['byplug']
                ),
                'date' => ''
            ));
        }
        $ais_options = get_option('ais_options');?>
        
        <style>
            #preloader { 
                display: none;
                vertical-align: middle;
                margin: -4px 0 0 10px ;
                width: 28px;
                height: 28px;
                background: transparent url('<?php echo plugins_url( 'images/loading.gif', plugin_basename( __FILE__ ) );?>') no-repeat center;
                background-size: 100%;
            }
            ul.ais-errors li{
                display: inline-block;
                padding: 3px 10px;
                color: #b94a48;
                background-color: #f2dede;
                border: 1px solid #eed3d7;
                border-radius: 3px;
            }
            #remove_xml{
                display: inline-block;
                vertical-align: baseline;
                text-indent: -9999px;
                width: 16px;
                height: 16px;
                background: transparent url('<?php echo plugins_url( 'images/remove.png', plugin_basename( __FILE__ ) );?>') no-repeat center;
                background-size: auto 100%;
            }
        </style>
        
        <script>
            jQuery(document).ready(function($){
                $('#ais_generate_btn').on('click touchstart', function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    
                    $('#preloader').css('display', 'inline-block');
                    
                    jQuery.ajax({
                        url : ajaxurl,
                        type : 'post',
                        data : {
                            action   : 'ais_generate',
                            _wpnonce : '<?php echo wp_create_nonce('ajax_ais_generate_nonce');?>',
                        },
                        success : function( response ) {
                            $('#preloader').css('display', 'none');
                            if( typeof response === 'object' && typeof response.data.error !== 'undefined' ) {
                                $('.ais-errors').append('<li>' + response.data.error + '</li>');
                            }else{
                                location.reload();
                            }
                        }
                    });
                });
                
                $('#remove_xml').on('click touchstart', function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    
                    jQuery.ajax({
                        url : ajaxurl,
                        type : 'post',
                        data : {
                            action   : 'ais_remove',
                            _wpnonce : '<?php echo wp_create_nonce('ajax_ais_remove_nonce');?>',
                        },
                        success : function( response ) {
                           $('#modified').css('display', 'none');
                           alert('<?php _e('XML successfully removed!')?>');
                        }
                    });
                    
                });
            });
        </script>

        <h2><?php _e('Advanced Image Sitemap','ais');?></h2>

        <form name="ais_settings_form" method="post" action="<?php echo $_SERVER['PHP_SELF']?>?page=ais">
            <?php if (function_exists ('wp_nonce_field') ) wp_nonce_field('ais_settings_form');?>
            <input type="hidden" name="action" value="ais_settings"/>

            <p>
                <input type="button" id="ais_generate_btn" name="ais_generate_btn" class="button button-primary" value="<?php _e('Generate Image Sitemap','ais')?>">
                <span id="preloader"></span>
                <ul class="ais-errors"></ul>
            </p>
            <p id="modified">
                <?php if (function_exists('ais_last_modified')) ais_last_modified();?>
            </p>            
            
            <div class="card pressthis">

                <h3 class="title"><?php _e('Image Sizes','ais')?></h3>
                <p><?php _e('The sizes listed below will be included in the generated xml file','ais')?></p>
                <?php if(!empty($ais_image_sizes)):?>
                    <table class="form-table">
                        <tbody>
                            <?php foreach($ais_image_sizes as $size => $params): if($params['width'] != 0 and $params['height'] != 0):
                                $check = ('on' === @$ais_options['sizes'][$size]) ? 'checked' : '';?>
                                <tr>
                                    <th scope="row"><input type="checkbox" name="sizes[<?php echo $size?>]" <?php echo $check?>>&nbsp<?php echo ucwords(str_replace('-', ' ', $size))?></th>
                                    <td><?php printf(__('width: %1$d | height: %2$d','ais'),$params['width'], $params['height'])?></td>
                                </tr>
                            <?php endif; endforeach;?>
                        </tbody>
                    </table>
                    <p><hr></p>
                <?php endif;?>

                <h3 class="title"><?php _e('Image XML Tags','ais')?></h3>
                <p><?php _e('The additional tags that will be presented in the generated xml file','ais')?></p>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php _e('Title','ais')?></th>
                            <td><input type="text" name="tags[title]" value="<?php echo $ais_options['tags']['title']?>" style="width:100%;">
                            <p class="description"><small><?php _e('The title of image. Type %NAME% here to get the title automatically from image file name.','ais')?></small></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Caption','ais')?></th>
                            <td><input type="text" name="tags[caption]" value="<?php echo $ais_options['tags']['caption']?>" style="width:100%;">
                            <p class="description"><small><?php _e('The caption of the image. For example: %NAME% by Example.com','ais')?></small></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Geo Location','ais')?></th>
                            <td><input type="text" name="tags[geo_location]" value="<?php echo $ais_options['tags']['geo_location']?>" style="width:100%;">
                            <p class="description"><small><?php _e('The geographic location of the image. For example: Limerick, Ireland','ais')?></small></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('License','ais')?></th>
                            <td><input type="text" name="tags[license]" value="<?php echo $ais_options['tags']['license']?>" style="width:100%;">
                            <p class="description"><small><?php _e('A URL to the license of the image','ais')?></small></p></td>
                        </tr>
                    </tbody>
                </table>
                <p class="description"><?php _e('You can use %NAME% tag in order to get real image file name. For example: your image japanese-cooking-knife.jpg will get the name japanese cooking knife when used in sitemap.','ais')?></p>
                <p><hr></p>
                <h3 class="title"><?php _e('Exclude Images','ais')?></h3>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php _e('Less than','ais')?></th>
                            <td><input type="text" name="exclude[bysize][width]" value="<?php echo $ais_options['exclude']['bysize']['width']?>" size="5">&nbspx&nbsp
                                <input type="text" name="exclude[bysize][height]" value="<?php echo $ais_options['exclude']['bysize']['height']?>" size="5">&nbsppx.
                            <p class="description"><small><?php _e("Pictures under this size won't be included into Image Sitemap.",'ais')?></small></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Used in plugins','ais')?></th>
                            <?php $check = ('on' === $ais_options['exclude']['byplug']) ? 'checked' : '';?>
                            <td><input type="checkbox" name="exclude[byplug]" <?php echo $check?>>
                            <p class="description"><small><?php _e("Pictures found in folders of WP plugins won't be included into Image Sitemap.",'ais')?></small></p></td>
                        </tr>
                    </tbody>
                </table>
                <p><input type="submit" name="ais_settings_btn" class="button button-primary" value="<?php _e('Update Settings','ais')?>"></p>
            </div>
        </form>
        
    <?php endif;
}