<?php
# SIMPLE_CHAT v7
session_start();
setlocale(LC_ALL, "ru_RU.UTF-8");
date_default_timezone_set('UTC');

# Проверяем существование базы данных и передачу параметра action
$dbh = new PDO("sqlite:db.db3");
$dbh->exec('PRAGMA journal_mode = WAL;');
$dbh->exec('PRAGMA foreign_keys = TRUE;');
$dbh->exec('PRAGMA synchronous = OFF;');
$dbh->exec('PRAGMA threads = 2;');
$dbh->exec('PRAGMA secure_delete = FALSE;');

# Подключаем библиотеку шифрования данных
require_once 'anubis.class.php';
$cypher = new Anubis();
$cypher->key = '4f2b46415e216b27274b38757140396f374f5c7e513759432c21364869';

# Параметры сервера
define("server_parameters", [
			'name' => "Simple Chat server",
			'mail' => "server@mail.ru",
			'on_dialog' => true,
			'on_secret_dialog' => true,
			'on_group_chat' => true,
			'on_channel' => true,
			'on_posts' => true,
			'on_vip_followers' => true,
			'on_likes' => true,
			'on_comments' => true,
			'on_favorites' => true,
			'last_version_client' => "7.0.0"
		]
	  );

# Устанавливаем параметры
$action = $_POST['action'];


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Функция генерации id4
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


	function gen_id() {
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Функция генерации временного пароля
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


	function gen_code($length = 6){
	  $chars = 'abdefhiknrstyzABDEFGHKNQRSTYZ23456789';
	  $numChars = strlen($chars);
	  $string = '';
	  for ($i = 0; $i < $length; $i++) {
		$string .= substr($chars, rand(1, $numChars) - 1, 1);
	  }
	  return strtoupper($string);
	}


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Функция обрезки изображений
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


	function ImageThumbnail($src, $dest, $desired_width, $mtype) {
	
		# Проверяем данные на соответствие
		try {
			
			/* Проверяем картинку на тип файла */
			if (!in_array($mtype, array('image/png', 'image/jpeg'))) return false;
			
			# Определяем тип функции
			if ($mtype == 'image/png') {
				$source_image = imagecreatefrompng($src);
			} elseif ($mtype == 'image/jpeg') { 
				$source_image = imagecreatefromjpeg($src);
			}
			
			# Проверяем на ошибки
			if (!$source_image) {
				throw new Exception('Ошибка передачи данных');
			}
			
			# Получаем параметры изображения
			$width = imagesx($source_image);
			$height = imagesy($source_image);
			$desired_height = floor($height * ($desired_width / $width));
			$virtual_image = imagecreatetruecolor($desired_width, $desired_height);
			
			# Проверяем на ошибки
			if (!$virtual_image) {
				throw new Exception('Ошибка передачи данных');
			}
			
			# Создаем новое изображение
			imagecopyresampled($virtual_image, $source_image, 0, 0, 0, 0, $desired_width, $desired_height, $width, $height);
			imagejpeg($virtual_image, $dest, 90);
			return $dest;
		
		} catch(Exception $e) {

			# Выводим сообщение
			$message = ['status' => $e->getMessage()];
			echo json_encode($message, JSON_NUMERIC_CHECK);

		}
	
	}
  
  
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Cклонение слов в зависимости от числа
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
  
  
	# Cклонение слов в зависимости от числа
	function declension_words($n,$words){
		return ($words[($n=($n=$n%100)>19?($n%10):$n)==1?0 : (($n>1&&$n<=4)?1:2)]);
	}
	

?>