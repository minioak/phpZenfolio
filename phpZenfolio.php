<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * AlbumParrot Zenfolio library. 
 *
 * 
 * @author		John Mitchell - AlbumParrot Dev Team
 * @package		AlbumParrot\Core\Modules\Albums\Libraries
 */
 

class Zenfolio_api
{
	
	protected static $config = array(
		'AppName' => 'Album Parrot/1.0 (http://www.albumparrot.com)',
		'timeout' => 60,
		'cache_expire' => 259200
	);
	
	protected static $page_size = 25;
	
	protected static $default = 'https://assets-albumparrot.netdna-ssl.com/addons/albumparrot/themes/albumparrot_app/img/icons/placeholder.png';
	
	public static function authenticate($user_id, $password) 
	{
		
		$zen = new phpZenfolio(self::$config);
		
		ci()->load->model('apps/album_user_m');
		
		try {
			$token = $zen->AuthenticatePlain($user_id, $password);
			
			$user = $zen->LoadPrivateProfile();
				
			ci()->album_user_m->update_by('user_id', ci()->current_user->id, array(
				'zenfolio_user' => $user['LoginName'],
				'zenfolio_token' => $token,
				'zenfolio_expires' => strtotime('+22 hours', now())
			));
			
			return $token;
		
		} catch (Exception $e) {
			return false;
		}
	}
	
	public static function visitor()
	{
		$zen = new phpZenfolio(self::$config);
		
		$token = $zen->AuthenticateVisitor('');
		$zen->setAuthToken($token);
		
		return $token;
		
		
	}
	
	public static function get_albums($token, $user_id, $slug = false)
	{
		$zen = new phpZenfolio(self::$config);
		$zen->enableCache('type=db');
		$zen->setAuthToken($token);
		$response = false;
		
		if ($slug) {
			$response = $zen->LoadGroup($slug, 'Level1', true);	
		} else {
			$response = $zen->LoadGroupHierarchy($user_id);
		}
	
		$result = array();
		
		foreach ($response['Elements'] as $album) {
			$highlight = '';
			if (isset($album['TitlePhoto'])) {
				$keyring = self::make_accessible($token, array($album['TitlePhoto']));
				$highlight = phpZenfolio::imageUrl($album['TitlePhoto'], 11).$keyring;
			} else {
				$highlight = self::$default;
			}
			$result[] = array(
				'id' => $album['Id'],
				'type' => strtolower($album['$type']),
				'title' => $album['Title'],
				'image' => $highlight,
				'protected' => $album['AccessDescriptor']['AccessType'] == 'Password' ? 'true' : 'false'
			);
		}
		
		return $result;
	}
	
	public static function get_photoset($token, $slug)
	{
		$zen = new phpZenfolio(self::$config);
		$zen->enableCache('type=db');
		$zen->setAuthToken($token);
		
		$response = $zen->LoadPhotoSet($slug, 'Level1', false);
		
		return $response;
	}
	
	public static function get_photos($token, $album_id, $page = 0)
	{
		$zen = new phpZenfolio(self::$config);
		$zen->enableCache('type=db');
		$zen->setAuthToken($token);
		
		$result = array();
		
		$response = $zen->LoadPhotoSetPhotos($album_id, $page * self::$page_size, self::$page_size);
		
		$keyring = self::make_accessible($token, $response);
		
		foreach ($response as &$photo) {
			$photo['ThumbURL'] = phpZenfolio::imageUrl($photo, 11).$keyring;
			$photo['DownloadURL'] = $photo['OriginalUrl'];
			
			$result[$photo['Id']] = $photo;
		}
		
		return $result;
	}
	
	public static function get_photo($token, $photo_id, $password = false) 
	{
		$zen = new phpZenfolio(self::$config);
		$zen->enableCache('type=db');
		$zen->setAuthToken($token);
		
		$result = false;
		
		$photo = $zen->LoadPhoto($photo_id, 'Level2');
		$affix = '&token='.$token;
		$keyring = self::keyring($token, '', $photo['AccessDescriptor']['RealmId'], $password);
		
		if (!empty($keyring)) {
			$affix = $affix.'&keyring='.$keyring; 
		}

		$photo['ThumbURL'] = phpZenfolio::imageUrl($photo, 11).$affix;
		$photo['DownloadURL'] = phpZenfolio::imageUrl($photo, 4);
		$photo['Filename'] = $photo['FileName'];
		$photo['ref'] = $photo['Id'];
		$photo['source'] = 'zenfolio';
			
		$result = $photo;
		
		return $result;
	}
	
	public static function get_download_token($photos, $password) 
	{
		$zen = new phpZenfolio(self::$config);
		
		$response = $zen->GetDownloadOriginalKey($photos, $password);
		
		return $response;

	}
	
	public static function get_all_photos($token, $album_id, $password = false) 
	{
		$zen = new phpZenfolio(self::$config);
		$zen->enableCache('type=db');
		$zen->setAuthToken($token);
		
		$result = array();
		
		$album = self::get_photoset($token, $album_id);
		
		$response = $zen->LoadPhotoSetPhotos($album_id, 0, $album['PhotoCount']);
		
		$keyring = self::make_accessible($token, $response, $password);
		
		foreach ($response as &$photo) {
			
			$photo['ThumbURL'] = phpZenfolio::imageUrl($photo, 11).$keyring;
			$photo['DownloadURL'] = phpZenfolio::imageUrl($photo, 4);
			$photo['ref'] = $photo['Id'];
			$photo['source'] = 'zenfolio';
			
			// Get Level 2 details
			/*$meta = $zen->LoadPhoto($photo['Id'], 'Level2');
			
			$photo['Filename'] = $meta['FileName'];*/
			
			$result[$photo['Id']] = $photo;
		}
		
		return $result;
	}
	
	public static function get_user($token, $user_id)
	{
		$zen = new phpZenfolio(self::$config);
		$zen->enableCache('type=db');
		$zen->setAuthToken($token);
		
		try {
			$response = $zen->LoadPublicProfile($user_id);
			
			return $user_id;
		} catch (Exception $e) {
		
			return false;
		}
	}
	
	private static function make_accessible($token, $photos, $password)
	{
		$realms = array();
		$keyring = false;
		
		if (!empty($password)) {
			foreach ($photos as $photo) {
				if (!in_array($photo['AccessDescriptor']['RealmId'], $realms) && $photo['AccessDescriptor']['AccessType'] != 'Public') {
					$keyring = self::keyring($token, $keyring, $photo['AccessDescriptor']['RealmId'], $password);
					$realms[] = $photo['AccessDescriptor']['RealmId'];
				}
			}
		}
		
		$result = '&token='.$token;
		
		if ($keyring) {
			$result = $result.'&keyring='.$keyring;
		}
		
		return $result;
	}
	
	private static function keyring($token, $keyring, $realm, $password) 
	{
		$zen = new phpZenfolio(self::$config);
		$zen->setAuthToken($token);
		$zen->setSecureOnly();
		
		try {
			return $zen->KeyringAddKeyPlain('', $realm, $password);
		} catch (Exception $e) {
			return false;
		}
		
	}

}
