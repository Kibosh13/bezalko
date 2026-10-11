<?php
if (!defined('ABSPATH')) exit;
add_action('after_setup_theme', function() { add_theme_support('title-tag'); add_theme_support('post-thumbnails'); });
function nl_defaults() {
 return [
 'hero_title'=>'Вкус, который собирает вместе.',
 'hero_text'=>'Лимонады и хлебный квас из Неверкино. Для встреч, разговоров и общего стола.',
 'about_short'=>'«Неверкинский лимонад» — производитель безалкогольных напитков в селе Неверкино Пензенской области. Мы выпускаем газированные напитки и хлебный квас.',
 'about_text'=>'Мы выпускаем газированные безалкогольные напитки и хлебный квас. Производство расположено в селе Неверкино, на улице Рабочей, 12В.',
 'phone'=>'+7 (84164) 2-11-10','mobile'=>'+7 (927) 388-38-15','email'=>'napitkizawod@yandex.ru',
 'address'=>'442480, Пензенская область, с. Неверкино, ул. Рабочая, д. 12В',
 'shop'=>'Адрес магазина: [заполнить]. Часы работы офиса и магазина: [заполнить].',
 'cities'=>'Города и точки продаж: [заполнить]. Сроки получения заказа: [заполнить].',
 'hero_photo'=>'','story_photo'=>'','awards_photo'=>'','logo_photo'=>'','form_enabled'=>'',
 'privacy_term'=>'[заполнить]','privacy_processors'=>'[заполнить]','privacy_responsible'=>'[заполнить]','privacy_host'=>'[заполнить]'
 ];
}
function nl_options() { return wp_parse_args(get_option('nl_content',[]),nl_defaults()); }
function nl_products() {
 $defaults=json_decode(file_get_contents(get_template_directory().'/products.json'),true);
 $saved=get_option('nl_products',[]);
 foreach ($defaults as &$p) { if (isset($saved[$p['id']])) $p=array_merge($p,$saved[$p['id']]); }
 return $defaults;
}
function nl_transform($html) {
 $base=get_template_directory_uri();
 $html=str_replace(['src="/assets/','href="/assets/'],['src="'.esc_url($base).'/assets/','href="'.esc_url($base).'/assets/'],$html);
 foreach (['catalog','about','gallery','documents','reviews','contacts','privacy'] as $slug) $html=str_replace('href="/'.$slug.'/"','href="'.esc_url(home_url('/'.$slug.'/')).'"',$html);
 return str_replace('href="/"','href="'.esc_url(home_url('/')).'"',$html);
}
function nl_template($name) {
 $allowed=['home','catalog','about','gallery','documents','reviews','contacts','privacy','header','footer'];
 if (!in_array($name,$allowed,true)) return '';
 return nl_transform(file_get_contents(get_template_directory().'/templates/'.$name.'.html'));
}
add_shortcode('nl_layout',function($attrs){$attrs=shortcode_atts(['page'=>'home'],$attrs);return nl_template($attrs['page']);});
add_action('wp_enqueue_scripts',function(){
 $base=get_template_directory_uri();
 wp_enqueue_style('nl-design',$base.'/design.css',[], '1.0.0');
 wp_enqueue_script('nl-app',$base.'/app.js',[], '1.0.0',true);
 $o=nl_options();$content=$o;
 // Keep the approved headline typography until it is edited.
 if ($content['hero_title']===nl_defaults()['hero_title']) unset($content['hero_title']);
 wp_add_inline_script('nl-app','window.NL_CONFIG='.wp_json_encode(['assetBase'=>$base.'/assets/','products'=>nl_products(),'content'=>$content,'ajaxUrl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('nl_callback'),'formEnabled'=>!empty($o['form_enabled']),'contactsUrl'=>home_url('/contacts/')]).';','before');
});
add_action('after_switch_theme',function(){
 $map=['home'=>'Главная','catalog'=>'Напитки','about'=>'О компании','gallery'=>'Галерея','documents'=>'Документы','reviews'=>'Отзывы','contacts'=>'Где купить','privacy'=>'Обработка персональных данных'];
 foreach($map as $slug=>$title){
  $existing=get_page_by_path($slug); if($existing) continue;
  $content=$slug==='privacy'?nl_template('privacy'):'[nl_layout page="'.$slug.'"]';
  $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$title,'post_name'=>$slug,'post_content'=>$content]);
  if($slug==='home'&&!is_wp_error($id)){update_option('show_on_front','page');update_option('page_on_front',$id);}
 }
 flush_rewrite_rules();
});
function nl_sanitize_content($input){
 $out=[];foreach(nl_defaults() as $key=>$default){
  $value=isset($input[$key])?$input[$key]:'';
  if($key==='form_enabled')$out[$key]=$value==='1'?'1':'';
  elseif($key==='email')$out[$key]=sanitize_email($value);
  elseif(str_ends_with($key,'_photo'))$out[$key]=esc_url_raw($value);
  else $out[$key]=sanitize_textarea_field($value);
 }return $out;
}
function nl_sanitize_products($input){
 $out=[];foreach(nl_products() as $p){$id=$p['id'];if(!isset($input[$id]))continue;
  foreach(['name','tag','desc','pack','photo','type'] as $key){$v=$input[$id][$key]??'';
   if($key==='photo')$out[$id][$key]=preg_match('~^https?://~',$v)?esc_url_raw($v):sanitize_file_name($v);
   elseif($key==='type')$out[$id][$key]=$v==='kvas'?'kvas':'lemonade';
   else $out[$id][$key]=sanitize_textarea_field($v);
  }
 }return $out;
}
add_action('admin_init',function(){register_setting('nl_settings','nl_content',['sanitize_callback'=>'nl_sanitize_content']);register_setting('nl_settings','nl_products',['sanitize_callback'=>'nl_sanitize_products']);});
add_action('admin_menu',function(){add_menu_page('Контент сайта','НЛ — Контент','manage_options','nl-content','nl_admin','dashicons-food',25);});
add_action('admin_enqueue_scripts',function($hook){if($hook!=='toplevel_page_nl-content')return;wp_enqueue_media();wp_enqueue_script('nl-admin',get_template_directory_uri().'/admin.js',['jquery'],'1.0.0',true);});
function nl_admin(){
 if(!current_user_can('manage_options'))return;$o=nl_options();
 $labels=['hero_title'=>'Заголовок первого экрана','hero_text'=>'Подзаголовок первого экрана','about_short'=>'Компания — краткое описание','about_text'=>'Компания — основное описание','phone'=>'Телефон / факс','mobile'=>'Мобильный телефон','email'=>'Почта и получатель заявок','address'=>'Адрес производства','shop'=>'Адрес магазина и часы работы','cities'=>'Города продаж и сроки','hero_photo'=>'Фото первого экрана','story_photo'=>'Фото в блоке о компании','awards_photo'=>'Фото наград','logo_photo'=>'Логотип'];
 echo '<div class="wrap"><h1>Содержание сайта</h1><p>Тексты, контакты и фотографии меняются здесь. Дополнительные блоки и политику редактируйте в разделе «Страницы».</p><form method="post" action="options.php">';settings_fields('nl_settings');
 echo '<table class="form-table">';foreach($labels as $key=>$label){echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td>';
 if(str_ends_with($key,'_photo')){echo '<input class="regular-text media-url" id="'.esc_attr($key).'" name="nl_content['.esc_attr($key).']" type="url" value="'.esc_attr($o[$key]).'"><button type="button" class="button nl-media" data-input="'.esc_attr($key).'">Выбрать из медиатеки</button>';}
 else{echo '<textarea class="large-text" rows="2" id="'.esc_attr($key).'" name="nl_content['.esc_attr($key).']">'.esc_textarea($o[$key]).'</textarea>';}
 echo '</td></tr>'; }echo '</table><h2>Напитки</h2><p>Пять карточек каталога. Цены не публикуются. Сохраните название и описание в соответствии с этикеткой.</p>';
 foreach(nl_products() as $p){$id=$p['id'];echo '<h3>'.esc_html($p['name']).'</h3><table class="form-table">';foreach(['name'=>'Название','tag'=>'Короткая подпись','desc'=>'Описание','pack'=>'Упаковка','photo'=>'Фотография'] as $key=>$label){$field='product-'.$id.'-'.$key;echo '<tr><th><label for="'.esc_attr($field).'">'.esc_html($label).'</label></th><td><input class="large-text" id="'.esc_attr($field).'" name="nl_products['.esc_attr($id).']['.esc_attr($key).']" value="'.esc_attr($p[$key]).'">';if($key==='photo')echo '<button type="button" class="button nl-media" data-input="'.esc_attr($field).'">Медиатека</button>';echo '</td></tr>';}
 echo '<tr><th>Категория</th><td><select name="nl_products['.esc_attr($id).'][type]"><option value="lemonade" '.selected($p['type'],'lemonade',false).'>Лимонады</option><option value="kvas" '.selected($p['type'],'kvas',false).'>Квас</option></select></td></tr></table>';}
 echo '<h2>Форма заявки</h2><p>До включения форма готовит письмо в почтовой программе посетителя. После включения отправляет заявку средствами WordPress на почту выше.</p><label><input type="checkbox" name="nl_content[form_enabled]" value="1" '.checked($o['form_enabled'],'1',false).'> Включить серверную отправку после заполнения политики и проверки доставки почты</label>';
 foreach(['privacy_term','privacy_processors','privacy_responsible','privacy_host'] as $key)echo '<input type="hidden" name="nl_content['.esc_attr($key).']" value="'.esc_attr($o[$key]).'">';
 submit_button('Сохранить изменения');echo '</form></div>';
}
add_action('wp_ajax_nl_callback','nl_callback');add_action('wp_ajax_nopriv_nl_callback','nl_callback');
function nl_callback(){
 check_ajax_referer('nl_callback');$o=nl_options();if(empty($o['form_enabled']))wp_send_json_error(['message'=>'Форма пока не подключена.'],503);
 if(!empty($_POST['website']))wp_send_json_error(['message'=>'Проверьте форму.'],400);
 $phone=sanitize_text_field(wp_unslash($_POST['phone']??''));$digits=preg_replace('/\D/','',$phone);
 if(strlen($digits)<10||strlen($digits)>15||empty($_POST['consent']))wp_send_json_error(['message'=>'Укажите телефон и согласие.'],400);
 $rate='nl_rate_'.hash('sha256',($_SERVER['REMOTE_ADDR']??'').wp_salt());if(get_transient($rate))wp_send_json_error(['message'=>'Заявка уже обрабатывается. Попробуйте через несколько минут.'],429);
 set_transient($rate,1,2*MINUTE_IN_SECONDS);
 $sent=wp_mail($o['email'],'Заявка на связь — Неверкинский лимонад',"Телефон: ".$phone."\nСогласие на обработку телефона для ответа на обращение подтверждено.\nДата: ".current_time('mysql'));
 if(!$sent){delete_transient($rate);wp_send_json_error(['message'=>'Почта не доставлена почтовому сервису.'],503);}
 wp_send_json_success(['message'=>'Заявка отправлена.']);
}
