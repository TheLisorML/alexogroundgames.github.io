<?php
setlocale(LC_ALL, "ru_RU.UTF-8");

	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем параметры
		$action = $_POST['action'];
		$user_id = (int) $_POST['user_id'];
		$session_id = $_POST['session_id'];
		
		# Если action пустой, то прерываем скрипт
		if (!empty($action) and !empty($user_id) and !empty($session_id)) {
			
			# Подключаем системные файл
			include_once 'db_func.php';
			
			# Получаем session_id пользователя
			$sth = $dbh->prepare('SELECT session_id, block FROM users WHERE id = ?');
			$sth->execute(array($user_id));

			# Получаем id сессии с базы данных
			$sessions = $sth->fetchAll(PDO::FETCH_ASSOC);
			$_session_id = $sessions[0]['session_id'];
			$_block = $sessions[0]['block'];
			
			# Если id сессий не совпадает, обрываем связь
			if ($_session_id !== $session_id or $_block == 1) {
				throw new Exception('Отказано в доступе');
			}
			
		} else {
			# Закрываем соединение с базой
			throw new Exception('Ошибка передачи данных');
		}
		
	} catch(Exception $e) {

		# Закрываем соединение с базой
		$dbh = null;

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);
		
		# Прерываем скрипт
		die();

	}


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Получаем записи
#---------------------------------------------------------------------------------------------------------------------------------------------------------------

	
switch ($action) {
case 'posts_list':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$access_posts = (int) $_POST['access_posts'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									   f.channel_id
								  FROM followers AS f
								 WHERE f.channel_id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($channel_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT p.*,
							   (CASE WHEN c.type == 1 OR 
										  c.type == 2 THEN (
									   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
										 FROM users
										WHERE id = p.user_id
								   )
							   WHEN c.type == 3 THEN c.name END) AS display_name,
							   (CASE WHEN c.type == 1 OR 
										  c.type == 2 THEN (
									   SELECT avatar
										 FROM users
										WHERE id = p.user_id
								   )
							   WHEN c.type == 3 AND 
									c.user_id == p.user_id THEN c.avatar ELSE (
									   SELECT avatar
										 FROM users
										WHERE id = p.user_id
								   )
							   END) AS display_avatar,
							   (CASE WHEN c.type = 1 THEN (
									   SELECT view_id
										 FROM followers
										WHERE user_id IS NOT p.user_id AND 
											  channel_id = p.channel_id
								   )
							   ELSE NULL END) AS read_this,
							   c.type,
							   c.secure,
							   (
								   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS reply_name
									 FROM users
										  JOIN
										  posts ON posts.id = p.reply_to
									WHERE users.id = posts.user_id
							   )
							   AS reply_name,
							   (
								   SELECT (CASE WHEN posts.post IS NULL THEN NULL ELSE posts.post END) AS reply_post
									 FROM posts
									WHERE posts.id = p.reply_to
							   )
							   AS reply_post,
							   (
								   SELECT COUNT(id) 
									 FROM likes
									WHERE post_id = p.id
							   )
							   AS likes,
							   (
								   SELECT id
									 FROM likes
									WHERE post_id = p.id AND 
										  user_id = ?
							   )
							   AS my_like,
							   (
								   SELECT COUNT(id) 
									 FROM comments
									WHERE post_id = p.id
							   )
							   AS count_comments,
							   (
								   SELECT id
									 FROM favorite
									WHERE post_id = p.id AND 
										  user_id = ?
							   )
							   AS my_favorite
						  FROM posts AS p
							   LEFT JOIN
							   channels AS c ON c.id = p.channel_id
						 WHERE p.channel_id = ? AND 
							   p.access = ? AND 
							   p.id > (CASE WHEN c.type = 1 THEN (
											  SELECT access_id
												FROM followers
											   WHERE user_id = ?
										  )
									  ELSE 0 END) AND 
							   access IN (0, (CASE WHEN c.type = 3 THEN (
									   SELECT access
										 FROM followers
										WHERE user_id = ? AND 
											  channel_id = c.id
								   )
							   END) ) 
						 ORDER BY p.pin DESC,
								  p.id DESC
						 LIMIT 20;");
		$sth->execute(array($user_id, $user_id, $channel_id, $access_posts, $user_id, $user_id));

		# Обрабатываем значения
		$posts = $sth->fetchAll(PDO::FETCH_ASSOC);
		$data = [];

		# get attaches
		if (count($posts) > 0) {
			foreach ($posts as $p) {

				# Запрос к БД
				$sth = $dbh->prepare("SELECT * FROM attaches WHERE post_id = ?;");
				$sth->execute(array($p['id']));
				$attaches = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Запрос к БД
				$sth = $dbh->prepare("SELECT l.*,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
												 FROM users
												WHERE id = l.user_id
										   )
										   AS display_name,
										   (
											   SELECT avatar
												 FROM users
												WHERE id = l.user_id
										   )
										   AS display_avatar
									  FROM likes AS l
										   JOIN
										   users AS u ON u.id = l.user_id
									 WHERE l.post_id = ?
									 ORDER BY l.date_create DESC
									 LIMIT 3;");
				$sth->execute(array($p['id']));
				$avatar_likes = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Запрос к БД
				$sth = $dbh->prepare("SELECT c.*,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
												 FROM users
												WHERE id = c.user_id
										   )
										   AS display_name,
										   (
											   SELECT avatar
												 FROM users
												WHERE id = c.user_id
										   )
										   AS display_avatar
									  FROM comments AS c
										   JOIN
										   users AS u ON u.id = c.user_id
									 WHERE c.post_id = ?
									 GROUP BY c.user_id
									 ORDER BY c.date_create DESC
									 LIMIT 3;");
				$sth->execute(array($p['id']));
				$avatar_comments = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Если диалог с флагом шифрования
				if ($p['secure']) {
					$p['post'] = $cypher->decrypt(base64_decode($p['post']));
				}
				
				# Если диалог с флагом шифрования
				if ($p['reply_post']) {
					if ($p['secure']) {
						$p['reply_post'] = $cypher->decrypt(base64_decode($p['reply_post']));
					}
					# обрезаем строку
					$p['reply_post'] = mb_substr($p['reply_post'], 0, 30);
				}

				# set data
				$data[$p['id']] = $p;
				$data[$p['id']]['attaches'] = $attaches;
				$data[$p['id']]['avatar_likes'] = $avatar_likes;
				$data[$p['id']]['avatar_comments'] = $avatar_comments;

			}
		}

		# Выводим сообщение
		$message = ['status' => 'success', 'data' => $data];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Получаем старые записи
#---------------------------------------------------------------------------------------------------------------------------------------------------------------

	
break;
case 'old_posts_list':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$start_post = (int) $_POST['start_post'];
		$private_posts = (int) $_POST['private_posts'];
		$str = $_POST['search_str'];
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($start_post)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($str)) {
			$str = "";
		} else {
			$str = " AND p.post LIKE '%$str%' ";
		}
	
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									   f.channel_id
								  FROM followers AS f
								 WHERE f.channel_id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($channel_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT p.*,
							   (CASE WHEN c.type == 1 OR 
										  c.type == 2 THEN (
									   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
										 FROM users
										WHERE id = p.user_id
								   )
							   WHEN c.type == 3 THEN c.name END) AS display_name,
							   (CASE WHEN c.type == 1 OR 
										  c.type == 2 THEN (
									   SELECT avatar
										 FROM users
										WHERE id = p.user_id
								   )
							   WHEN c.type == 3 AND 
									c.user_id == p.user_id THEN c.avatar ELSE (
									   SELECT avatar
										 FROM users
										WHERE id = p.user_id
								   )
							   END) AS display_avatar,
							   (CASE WHEN c.type = 1 THEN (
									   SELECT view_id
										 FROM followers
										WHERE user_id IS NOT p.user_id AND 
											  channel_id = p.channel_id
								   )
							   ELSE NULL END) AS read_this,
							   c.type,
							   c.secure,
							   (
								   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS reply_name
									 FROM users
										  JOIN
										  posts ON posts.id = p.reply_to
									WHERE users.id = posts.user_id
							   )
							   AS reply_name,
							   (
								   SELECT (CASE WHEN posts.post IS NULL THEN NULL ELSE posts.post END) AS reply_post
									 FROM posts
									WHERE posts.id = p.reply_to
							   )
							   AS reply_post,
							   (
								   SELECT COUNT(id) 
									 FROM likes
									WHERE post_id = p.id
							   )
							   AS likes,
							   (
								   SELECT id
									 FROM likes
									WHERE post_id = p.id AND 
										  user_id = ?
							   )
							   AS my_like,
							   (
								   SELECT COUNT(id) 
									 FROM comments
									WHERE post_id = p.id
							   )
							   AS count_comments,
							   (
								   SELECT id
									 FROM favorite
									WHERE post_id = p.id AND 
										  user_id = ?
							   )
							   AS my_favorite
						  FROM posts AS p
							   LEFT JOIN
							   channels AS c ON c.id = p.channel_id
						 WHERE p.channel_id = ? AND 
							   p.access = ? AND 
							   p.id > (CASE WHEN c.type = 1 THEN (
											  SELECT access_id
												FROM followers
											   WHERE user_id = ?
										  )
									  ELSE 0 END) AND 
							   access IN (0, (CASE WHEN c.type = 3 THEN (
									   SELECT access
										 FROM followers
										WHERE user_id = ? AND 
											  channel_id = c.id
								   )
							   END) ) AND 
							   p.id < ?
							   $str
						 ORDER BY p.id DESC
						 LIMIT 20;");
		$sth->execute(array($user_id, $user_id, $channel_id, $private_posts, $user_id, $user_id, $start_post));
		
		# Обрабатываем значения
		$posts = $sth->fetchAll(PDO::FETCH_ASSOC);
		$data = [];
		
		# get attaches
		if (count($posts) > 0) {
			foreach ($posts as $p) {
					
				# Запрос к БД
				$sth = $dbh->prepare("SELECT * FROM attaches WHERE post_id = ?;");
				$sth->execute(array($p['id']));
				$attaches = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Запрос к БД
				$sth = $dbh->prepare("SELECT l.*,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
												 FROM users
												WHERE id = l.user_id
										   )
										   AS display_name,
										   (
											   SELECT avatar
												 FROM users
												WHERE id = l.user_id
										   )
										   AS display_avatar
									  FROM likes AS l
										   JOIN
										   users AS u ON u.id = l.user_id
									 WHERE l.post_id = ?
									 ORDER BY l.date_create DESC
									 LIMIT 3;");
				$sth->execute(array($p['id']));
				$avatar_likes = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Запрос к БД
				$sth = $dbh->prepare("SELECT c.*,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
												 FROM users
												WHERE id = c.user_id
										   )
										   AS display_name,
										   (
											   SELECT avatar
												 FROM users
												WHERE id = c.user_id
										   )
										   AS display_avatar
									  FROM comments AS c
										   JOIN
										   users AS u ON u.id = c.user_id
									 WHERE c.post_id = ?
									 GROUP BY c.user_id
									 ORDER BY c.date_create DESC
									 LIMIT 3;");
				$sth->execute(array($p['id']));
				$avatar_comments = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Если диалог с флагом шифрования
				if ($p['secure']) {
					$p['post'] = $cypher->decrypt(base64_decode($p['post']));
				}
				
				# Если диалог с флагом шифрования
				if ($p['reply_post']) {
					if ($p['secure']) {
						$p['reply_post'] = $cypher->decrypt(base64_decode($p['reply_post']));
					}
					# обрезаем строку
					$p['reply_post'] = mb_substr($p['reply_post'], 0, 30);
				}
				
				# set data
				$data[$p['id']] = $p;
				$data[$p['id']]['attaches'] = $attaches;
				$data[$p['id']]['avatar_likes'] = $avatar_likes;
				$data[$p['id']]['avatar_comments'] = $avatar_comments;
					
			}
		}
		
		# Выводим сообщение
		$message = ['status' => 'success', 'data' => $data];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Получаем избранные записи
#---------------------------------------------------------------------------------------------------------------------------------------------------------------

	
break;
case 'favorites_list':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$folder_id = (int) $_POST['folder_id'];
		
		# Проверяем значения
		if (empty($folder_id)) {
			$folder_id = null;
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT p.*,
								   (CASE WHEN c.type == 1 OR 
											  c.type == 2 THEN (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
											 FROM users
											WHERE id = p.user_id
									   )
								   WHEN c.type == 3 THEN c.name END) AS display_name,
								   (CASE WHEN c.type == 1 OR 
											  c.type == 2 THEN (
										   SELECT avatar
											 FROM users
											WHERE id = p.user_id
									   )
								   WHEN c.type == 3 AND 
										c.user_id == p.user_id THEN c.avatar ELSE (
										   SELECT avatar
											 FROM users
											WHERE id = p.user_id
									   )
								   END) AS display_avatar,
								   (CASE WHEN c.type = 1 THEN (
										   SELECT view_id
											 FROM followers
											WHERE user_id IS NOT p.user_id AND 
												  channel_id = p.channel_id
									   )
								   ELSE NULL END) AS read_this,
								   c.type,
								   c.secure,
								   (
									   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS reply_name
										 FROM users
											  JOIN
											  posts ON posts.id = p.reply_to
										WHERE users.id = posts.user_id
								   )
								   AS reply_name,
								   (
									   SELECT (CASE WHEN posts.post IS NULL THEN NULL ELSE posts.post END) AS reply_post
										 FROM posts
										WHERE posts.id = p.reply_to
								   )
								   AS reply_post,
								   (
									   SELECT COUNT(id) 
										 FROM likes
										WHERE post_id = p.id
								   )
								   AS likes,
								   (
									   SELECT id
										 FROM likes
										WHERE post_id = p.id AND 
											  user_id = ?
								   )
								   AS my_like,
								   (
									   SELECT COUNT(id) 
										 FROM comments
										WHERE post_id = p.id
								   )
								   AS count_comments,
								   (
									   SELECT id
										 FROM favorite
										WHERE post_id = p.id AND 
											  user_id = ?
								   )
								   AS my_favorite
							  FROM posts AS p
								   LEFT JOIN
								   channels AS c ON c.id = p.channel_id
								   LEFT JOIN
								   favorite AS f ON f.post_id = p.id
							 WHERE f.user_id = ? AND 
								   f.f_id = ?
							 LIMIT 20;");
		$sth->execute(array($user_id, $user_id, $user_id, $folder_id));
		
		# Обрабатываем значения
		$posts = $sth->fetchAll(PDO::FETCH_ASSOC);
		$data = [];
		
		# get attaches
		if (count($posts) > 0) {
			foreach ($posts as $p) {

				# Запрос к БД
				$sth = $dbh->prepare("SELECT * FROM attaches WHERE post_id = ?;");
				$sth->execute(array($p['id']));
				$attaches = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Запрос к БД
				$sth = $dbh->prepare("SELECT l.*,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
												 FROM users
												WHERE id = l.user_id
										   )
										   AS display_name,
										   (
											   SELECT avatar
												 FROM users
												WHERE id = l.user_id
										   )
										   AS display_avatar
									  FROM likes AS l
										   JOIN
										   users AS u ON u.id = l.user_id
									 WHERE l.post_id = ?
									 ORDER BY l.date_create DESC
									 LIMIT 3;");
				$sth->execute(array($p['id']));
				$avatar_likes = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Запрос к БД
				$sth = $dbh->prepare("SELECT c.*,
										   (
											   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
												 FROM users
												WHERE id = c.user_id
										   )
										   AS display_name,
										   (
											   SELECT avatar
												 FROM users
												WHERE id = c.user_id
										   )
										   AS display_avatar
									  FROM comments AS c
										   JOIN
										   users AS u ON u.id = c.user_id
									 WHERE c.post_id = ?
									 GROUP BY c.user_id
									 ORDER BY c.date_create DESC
									 LIMIT 3;");
				$sth->execute(array($p['id']));
				$avatar_comments = $sth->fetchAll(PDO::FETCH_ASSOC);
				
				# Если диалог с флагом шифрования
				if ($p['secure']) {
					$p['post'] = $cypher->decrypt(base64_decode($p['post']));
				}
				
				# Если диалог с флагом шифрования
				if ($p['reply_post']) {
					if ($p['secure']) {
						$p['reply_post'] = $cypher->decrypt(base64_decode($p['reply_post']));
					}
					# обрезаем строку
					$p['reply_post'] = mb_substr($p['reply_post'], 0, 30);
				}

				# set data
				$data[$p['id']] = $p;
				$data[$p['id']]['attaches'] = $attaches;
				$data[$p['id']]['avatar_likes'] = $avatar_likes;
				$data[$p['id']]['avatar_comments'] = $avatar_comments;

			}
		}
		
		# Выводим сообщение
		$message = ['status' => 'success', 'data' => $data];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Создание поста
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'create_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$channel_id = (int) $_POST['channel_id'];
		$post = $_POST['post'];
		$access_post = (int) $_POST['access'];
		$header = $_POST['header'];
		$reply_to = $_POST['reply_to'];
		$attach = $_FILES;
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($channel_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if ($header == "") {
			$header = NULL;
		}
		
		# Проверяем значения
		if (empty($reply_to)) {
			$reply_to = NULL;
		}
		
		# Проверяем значения
		if ($post == "" and empty($attach)) {
			throw new Exception('Введите сообщение');
		}
		
		# Проверяем значения
		if (mb_strlen($post, 'utf-8') > 16834) {
			throw new Exception('Слишком длинный текст');
		}
		
		# security ------------------------------------------------------------------------------------------------------
		$sth = $dbh->prepare("SELECT u.write_me,
								   (
									   SELECT id
										 FROM friends
										WHERE user_id = u.id AND 
											  friend_id = ?
								   )
								   AS friend,
								   (
									   SELECT type
										 FROM channels
										WHERE id = ?
								   )
								   AS channel_type,
								   (
									   SELECT secure
										 FROM channels
										WHERE id = ?
								   )
								   AS secure,
								   (
									   SELECT visible
										 FROM followers
										WHERE channel_id = ? AND 
											  user_id IS NOT ?
								   ) AS visible,
								   (
									   SELECT user_id
										 FROM followers
										WHERE channel_id = ? AND 
											  user_id IS NOT ?
								   )
								   AS follower_id
							  FROM users AS u
							 WHERE u.id = (
											  SELECT user_id
												FROM followers
											   WHERE channel_id = ? AND 
													 user_id IS NOT ?
										  );");
		$sth->execute(array($user_id, $channel_id, $channel_id, $channel_id, $user_id, $channel_id, $user_id, $channel_id, $user_id));
		
		# access denied ------------------------------------------------------------------------------------------------------
		$access = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (($access[0]['channel_type'] == 1) AND ($access[0]['write_me'] == 0) AND empty($access[0]['friend'])) {
			throw new Exception('Пользователь ограничил отправку сообщений');
		}
		
		# Запрос к БД
		if ($access[0]['visible'] == 0 AND $access[0]['channel_type'] == 1) {
			$stmt = $dbh->prepare('UPDATE followers SET visible=? WHERE user_id=? AND channel_id=?');
			$stmt->execute(array(1, $access[0]['follower_id'], $channel_id));
		}
		
		# Если диалог с флагом шифрования
		if ($access[0]['secure']) {
			$post = base64_encode($cypher->encrypt($post));
		}
		
		# Если channel type != 3
		if ($access[0]['channel_type'] != 3) {
			//$header = null;
			//$access_post = 0;
		}
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO posts (user_id, channel_id, post, reply_to, header, access, date_create) VALUES (?, ?, ?, ?, ?, ?, ?);');
		$stmt->execute(array($user_id, $channel_id, $post, $reply_to, $header, $access_post, $date_create));
		$last_post_id = $dbh->lastInsertId();
		
		# Проверяем сообщение на прикрепленные файлы
		if (!empty($attach)) {
			if($attach['attach']['error'] == 0) {
				
				# Создаём объект для работы с ZIP-архивами
				$zip = new ZipArchive;
				$tmp_zip_path = '../attach/'.gen_id();
				if ($zip->open($attach["attach"]["tmp_name"]) === TRUE) {
					$zip->extractTo($tmp_zip_path);
					$zip->close();
				} else {
					throw new Exception('При обработке данных произошла ошибка');
				}
				
				# Проходим по распакованным файлам
				$skip = array('.', '..');
				$tmp_files = scandir($tmp_zip_path);
				foreach($tmp_files as $tmp_file) {
					
					if(!in_array($tmp_file, $skip)) {
						
						$salt = gen_code(11);
						$name = mb_strtolower(urldecode(basename($tmp_zip_path.'/'.$tmp_file)));
						$size = filesize($tmp_zip_path.'/'.$tmp_file);
						
						# Получаем MIME TYPE файла
						$finfo = finfo_open(FILEINFO_MIME_TYPE);
						$mtype = finfo_file($finfo, $tmp_zip_path.'/'.$tmp_file);
						finfo_close($finfo);
						
						# get id attach
						$stmt = $dbh->prepare('INSERT INTO attaches (post_id, salt, name, size, mime, date_upload) VALUES (?, ?, ?, ?, ?, ?)');
						$stmt->execute(array($last_post_id, $salt, $name, $size, $mtype, $date_create));
						$attach_id = $dbh->lastInsertId();
						
						# patch attach
						$path = '../attach/'.$salt.'_'.$attach_id.'.smh';
				
						# Перемещяем файл в дирректорию
						if (!rename($tmp_zip_path.'/'.$tmp_file, $path)) {
							throw new Exception('Возможно не выполнились некоторые действия');
						} else {
							
							# Создаем thumbnail фотографии для вывода в клиенте
							# А так же пересоздаем оригинал изображения удаляя при этом все метаданные внутри файла
							$type_img = array('image/jpeg','image/png');
							if (in_array($mtype, $type_img)) {
							
								# Создаем thumb файл
								$mime = "image/jpeg";
								$path_thumb = '../attach/'.$salt.'_'.$attach_id.'.thumb.jpg';
								$image_size = getimagesize($path);
								
								# Пересоздаем оригинал изображения
								$th_w = $image_size[0];
								ImageThumbnail($path, $path, $th_w, $mtype);
								
								# Создаем миниатюру фотографии
								if ($image_size[0] < 180) {
									$th_w = $image_size[0];
								} else {
									$th_w = 180;
								}
								ImageThumbnail($path, $path_thumb, $th_w, $mime);
								
								# Вычисляем размеры thumbnail
								$thumb_size = getimagesize($path_thumb);
								
								# update attach data
								$stmt = $dbh->prepare('UPDATE attaches SET w=?, h=? WHERE id=?');
								$stmt->execute(array($thumb_size[0], $thumb_size[1], $attach_id));
								
							} else {
								
								# Сбрасываем переменные
								$mime = NULL;
								$thumb_size = NULL;
								
							}
							
						}
						
					}
					
				}
				
				# Удаляем временную директорию zip архива
				rmdir($tmp_zip_path);
				
			}
		}
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Загрузка записи для редактирования
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'do_edit_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT p.*,
								   c.type as channel_type,
								   c.secure
							  FROM posts AS p
								   JOIN
								   channels AS c ON c.id = p.channel_id
							 WHERE p.id = ?;");
		$sth->execute(array($post_id));
		
		# Обрабатываем значения
		$post = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Если диалог с флагом шифрования
		if ($post[0]['secure']) {
			$post[0]['post'] = $cypher->decrypt(base64_decode($post[0]['post']));
		}
		
		# Получаем файлы записи в БД
		$sth = $dbh->prepare("SELECT * FROM attaches WHERE post_id = ?;");
		$sth->execute(array($post_id));
		
		# Обработка полученных данных
		$result_attach = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Выводим сообщение
		$message = ['status' => 'success', 'data' => ['post' => $post, 'attaches' => $result_attach]];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Сохраняем изменения в записи
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'edit_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		$post = $_POST['post'];
		$access = (int) $_POST['access'];
		$header = $_POST['header'];
		$date_edited = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($header)) {
			$header = null;
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									   p.user_id,
									   c.user_id as admin_id,
									   c.type
								  FROM followers AS f
									   JOIN
									   posts AS p ON p.channel_id = f.channel_id
									   JOIN
									   channels AS c ON c.id = p.channel_id
								 WHERE p.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($post_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# Проверки безопасности
		if ($exist[0]['type'] == 3 and $exist[0]['admin_id'] != $user_id) {
			
			# Проверки безопасности
			if ($exist[0]['user_id'] != $user_id) {
				throw new Exception('У Вас нет доступа');
			}
			
		} elseif ($exist[0]['type'] == 1 or $exist[0]['type'] == 2) {
			
			# Проверки безопасности
			if ($exist[0]['user_id'] != $user_id) {
				throw new Exception('У Вас нет доступа');
			}
			
		}
		
		# ---------------------------------------------------------------------------------------
		
		#----------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT c.secure
							  FROM posts AS p
								   JOIN
								   channels AS c ON c.id = p.channel_id
							 WHERE p.id = ?;");
		$sth->execute(array($post_id));
		
		# Обрабатываем значения
		$secure = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Если диалог с флагом шифрования
		if ($secure[0]['secure']) {
			$post = base64_encode($cypher->encrypt($post));
		}
		
		#----------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE posts SET post=?, access=?, header=?, date_edited=? WHERE id=?');
		$stmt->execute(array($post, $access, $header, $date_edited, $post_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Сохраняем изменения в записи
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'get_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT p.*,
								   (CASE WHEN c.type == 1 OR 
											  c.type == 2 THEN (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
											 FROM users
											WHERE id = p.user_id
									   )
								   WHEN c.type == 3 THEN c.name END) AS display_name,
								   (CASE WHEN c.type == 1 OR 
											  c.type == 2 THEN (
										   SELECT avatar
											 FROM users
											WHERE id = p.user_id
									   )
								   WHEN c.type == 3 AND 
										c.user_id == p.user_id THEN c.avatar ELSE (
										   SELECT avatar
											 FROM users
											WHERE id = p.user_id
									   )
								   END) AS display_avatar,
								   (CASE WHEN c.type = 1 THEN (
										   SELECT view_id
											 FROM followers
											WHERE user_id IS NOT p.user_id AND 
												  channel_id = p.channel_id
									   )
								   ELSE NULL END) AS read_this,
								   c.type,
								   (
									   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS reply_name
										 FROM users
											  JOIN
											  posts ON posts.id = p.reply_to
										WHERE users.id = posts.user_id
								   )
								   AS reply_name,
								   (
									   SELECT (CASE WHEN posts.post IS NULL THEN NULL ELSE substr(posts.post, 1, 30) END) AS reply_post
										 FROM posts
										WHERE posts.id = p.reply_to
								   )
								   AS reply_post,
								   (
									   SELECT COUNT(id) 
										 FROM likes
										WHERE post_id = p.id
								   )
								   AS likes,
								   (
									   SELECT id
										 FROM likes
										WHERE post_id = p.id AND 
											  user_id = ?
								   )
								   AS my_like,
								   (
									   SELECT COUNT(id) 
										 FROM comments
										WHERE post_id = p.id
								   )
								   AS count_comments,
								   (
									   SELECT id
										 FROM favorite
										WHERE post_id = p.id AND 
											  user_id = ?
								   )
								   AS my_favorite
							  FROM posts AS p
								   LEFT JOIN
								   channels AS c ON c.id = p.channel_id
							 WHERE p.id = ?;");
		$sth->execute(array($user_id, $user_id, $post_id));
		$post = $sth->fetchAll(PDO::FETCH_ASSOC);
	
		# get attaches
		if (count($post) > 0) {

			# Запрос к БД
			$sth = $dbh->prepare("SELECT * FROM attaches WHERE post_id = ?;");
			$sth->execute(array($post_id));
			$attaches = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			# Запрос к БД
			$sth = $dbh->prepare("SELECT l.*,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
											 FROM users
											WHERE id = l.user_id
									   )
									   AS display_name,
									   (
										   SELECT avatar
											 FROM users
											WHERE id = l.user_id
									   )
									   AS display_avatar
								  FROM likes AS l
									   JOIN
									   users AS u ON u.id = l.user_id
								 WHERE l.post_id = ?
								 ORDER BY l.date_create DESC
								 LIMIT 3;");
			$sth->execute(array($post_id));
			$avatar_likes = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			# Запрос к БД
			$sth = $dbh->prepare("SELECT c.*,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
											 FROM users
											WHERE id = c.user_id
									   )
									   AS display_name,
									   (
										   SELECT avatar
											 FROM users
											WHERE id = c.user_id
									   )
									   AS display_avatar
								  FROM comments AS c
									   JOIN
									   users AS u ON u.id = c.user_id
								 WHERE c.post_id = ?
								 GROUP BY c.user_id
								 ORDER BY c.date_create DESC
								 LIMIT 3;");
			$sth->execute(array($post_id));
			$avatar_comments = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			# Запрос к БД
			$sth = $dbh->prepare("SELECT *,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
											 FROM users
											WHERE id = c.user_id
									   )
									   AS display_name,
									   (
										   SELECT avatar
											 FROM users
											WHERE id = c.user_id
									   )
									   AS display_avatar,
									   (
										   SELECT (CASE WHEN display_name == 0 THEN (first_name || ' ' || last_name) ELSE nickname END) AS reply_name
											 FROM users
												  JOIN
												  comments AS cc ON cc.id = c.reply_to
											WHERE users.id = cc.user_id
									   )
									   AS reply_name,
									   (
										   SELECT (CASE WHEN ccc.text IS NULL THEN NULL ELSE substr(ccc.text, 1, 30) END) AS reply_post
											 FROM comments AS ccc
											WHERE ccc.id = c.reply_to
									   )
									   AS reply_post,
									   (
										   SELECT access
											 FROM followers
											WHERE id = c.user_id
									   )
									   AS access
								  FROM comments AS c
								 WHERE post_id = ?;");
			$sth->execute(array($post_id));
			$comments = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			# set data
			$data = $post[0];
			$data['attaches'] = $attaches;
			$data['comments'] = $comments;
			$data['avatar_likes'] = $avatar_likes;
			$data['avatar_comments'] = $avatar_comments;
			
		}
		
		# Выводим сообщение
		$message = ['status' => 'success',
					'post' => $data
				   ];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}


#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Удаляем файл
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_attach':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$attach_id = (int) $_POST['attach_id'];
		$date_edited = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($attach_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id
								  FROM followers AS f
									   JOIN
									   attaches AS a ON a.post_id = p.id
									   JOIN
									   posts AS p ON p.channel_id = f.channel_id
									   JOIN
									   channels AS c ON c.id = p.channel_id
								 WHERE a.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($attach_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Получаем файлы сообщения в БД
		$sth = $dbh->prepare("SELECT a.*,
								   p.id AS post_id,
								   p.user_id
							  FROM attaches AS a
								   LEFT JOIN
								   posts AS p ON p.id = a.post_id
							 WHERE a.id = ?;");
		$sth->execute(array($attach_id));

		# Обработка полученных данных
		$result = $sth->fetchAll(PDO::FETCH_ASSOC);
		if (count($result) == 1 and $result[0]['user_id'] == $user_id) {
			
			# Удаляем файлы с сервера
			unlink('../attach/'.$result[0]['salt'].'_'.$result[0]['id'].'.smh');
			
			# Если есть thumb
			$type_img = array('image/jpeg','image/png');
			if (in_array($result[0]['mime'], $type_img)) {
				unlink('../attach/'.$result[0]['salt'].'_'.$result[0]['id'].'.thumb.jpg');
			}

			# Запрос к БД
			$stmt = $dbh->prepare('DELETE FROM attaches WHERE id=? and salt=?;');
			$stmt->execute(array($result[0]['id'], $result[0]['salt']));
			
			# Запрос к БД
			$stmt = $dbh->prepare('UPDATE posts SET date_edited=? WHERE id=?');
			$stmt->execute(array($date_edited, $result[0]['post_id']));
			
		} else {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Выделяем запись
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'highlight_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		$date_edited = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									   c.user_id as admin_id
								  FROM followers AS f
									   JOIN
									   posts AS p ON p.channel_id = f.channel_id
									   JOIN
									   channels AS c ON c.id = p.channel_id
								 WHERE p.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($post_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# Проверки безопасности
		if ($exist[0]['admin_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE posts SET accent = (CASE WHEN accent == 0 THEN 1 ELSE 0 END), date_edited = ? WHERE id=?;');
		$stmt->execute(array($date_edited, $post_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Доступ к записи
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'access_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		$date_edited = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									   c.user_id as admin_id
								  FROM followers AS f
									   JOIN
									   posts AS p ON p.channel_id = f.channel_id
									   JOIN
									   channels AS c ON c.id = p.channel_id
								 WHERE p.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($post_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# Проверки безопасности
		if ($exist[0]['admin_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE posts SET access = (CASE WHEN access == 0 THEN 1 ELSE 0 END), date_edited = ? WHERE id=?;');
		$stmt->execute(array($date_edited, $post_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Закрепяем запись
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'pin_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		$date_edited = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									   c.user_id as admin_id
								  FROM followers AS f
									   JOIN
									   posts AS p ON p.channel_id = f.channel_id
									   JOIN
									   channels AS c ON c.id = p.channel_id
								 WHERE p.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($post_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# Проверки безопасности
		if ($exist[0]['admin_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE posts SET pin = (CASE WHEN pin == 0 THEN 1 ELSE 0 END), date_edited = ? WHERE id=?;');
		$stmt->execute(array($date_edited, $post_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}

	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Загружаем файлы для сообщения в режиме редактирования
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'upload_attach_post':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		$attach = $_FILES;
		$date_upload = date("Y-m-d H:i:s");
		
		# Проверка на сообщение
		if (empty($attach)) {
			throw new Exception('Файлы не загружены');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id
								  FROM followers AS f
									   JOIN
									   posts AS p ON p.channel_id = f.channel_id
									   JOIN
									   channels AS c ON c.id = p.channel_id
								 WHERE p.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($post_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Проверяем сообщение на прикрепленные файлы
		if (!empty($attach)) {
			if($attach['attach']['error'] == 0) {
				
				# Создаём объект для работы с ZIP-архивами
				$zip = new ZipArchive;
				$tmp_zip_path = '../attach/'.gen_id();
				if ($zip->open($attach["attach"]["tmp_name"]) === TRUE) {
					$zip->extractTo($tmp_zip_path);
					$zip->close();
				} else {
					throw new Exception('При обработке данных произошла ошибка');
				}
				
				# Проходим по распакованным файлам
				$skip = array('.', '..');
				$tmp_files = scandir($tmp_zip_path);
				foreach($tmp_files as $tmp_file) {
					
					if(!in_array($tmp_file, $skip)) {
						
						$salt = gen_code(11);
						$name = mb_strtolower(urldecode(basename($tmp_zip_path.'/'.$tmp_file)));
						$size = filesize($tmp_zip_path.'/'.$tmp_file);
						
						# Получаем MIME TYPE файла
						$finfo = finfo_open(FILEINFO_MIME_TYPE);
						$mtype = finfo_file($finfo, $tmp_zip_path.'/'.$tmp_file);
						finfo_close($finfo);
						
						# get id attach
						$stmt = $dbh->prepare('INSERT INTO attaches (post_id, salt, name, size, mime, date_upload) VALUES (?, ?, ?, ?, ?, ?)');
						$stmt->execute(array($post_id, $salt, $name, $size, $mtype, $date_upload));
						$attach_id = $dbh->lastInsertId();
						
						# patch attach
						$path = '../attach/'.$salt.'_'.$attach_id.'.smh';
				
						# Перемещяем файл в дирректорию
						if (!rename($tmp_zip_path.'/'.$tmp_file, $path)) {
							throw new Exception('Возможно не выполнились некоторые действия');
						} else {
							
							# Создаем thumbnail фотографии для вывода в клиенте
							# А так же пересоздаем оригинал изображения удаляя при этом все метаданные внутри файла
							$type_img = array('image/jpeg','image/png');
							if (in_array($mtype, $type_img)) {
							
								# Создаем thumb файл
								$mime = "image/jpeg";
								$path_thumb = '../attach/'.$salt.'_'.$attach_id.'.thumb.jpg';
								$image_size = getimagesize($path);
								
								# Пересоздаем оригинал изображения
								$th_w = $image_size[0];
								ImageThumbnail($path, $path, $th_w, $mtype);
								
								# Создаем миниатюру фотографии
								if ($image_size[0] < 180) {
									$th_w = $image_size[0];
								} else {
									$th_w = 180;
								}
								ImageThumbnail($path, $path_thumb, $th_w, $mime);
								
								# Вычисляем размеры thumbnail
								$thumb_size = getimagesize($path_thumb);
								
								# update attach data
								$stmt = $dbh->prepare('UPDATE attaches SET w=?, h=? WHERE id=?');
								$stmt->execute(array($thumb_size[0], $thumb_size[1], $attach_id));
								
							} else {
								
								# Сбрасываем переменные
								$mime = NULL;
								$thumb_size = NULL;
								
							}
							
							# update date
							$date_edited = date("Y-m-d H:i:s");
							$stmt = $dbh->prepare('UPDATE posts SET date_edited=? WHERE id=?');
							$stmt->execute(array($date_edited, $post_id));
							
							# Получаем файлы сообщения в БД
							$sth = $dbh->prepare("SELECT * FROM attaches WHERE post_id = ?;");
							$sth->execute(array($post_id));
										
							# Обработка полученных данных
							$result_attach = $sth->fetchAll(PDO::FETCH_ASSOC);
							
						}
						
					}
					
				}
				
				# Удаляем временную директорию zip архива
				rmdir($tmp_zip_path);
				
			}
		}
		
		# Выводим сообщение
		$message = ['status' => 'success', 'attach' => $result_attach];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Скачиваем прикрепленный файл к сообщению
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'download_attaches':


	# Проверяем данные на соответствие
	try {
		
		# Устанавливаем значения
		$id = (int) $_POST['id'];
		$salt = $_POST['salt'];
		
		# Проверяем значения
		if (empty($id)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Проверяем значения
		if (empty($salt)) {
			throw new Exception('Ошибка передачи данных');
		}
		
		# Получаем текст сообщения в БД
		$sth = $dbh->prepare("SELECT * FROM attaches WHERE id=? AND salt=?;");
		$sth->execute(array($id, $salt));
					
		# Обработка полученных данных
		$attaches = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверяем ответ от сервера
		if (count($attaches) == 1) {
			
			# Выводим сообщение
			$message = ['status' => 'success', 
						'name' => $attaches[0]['name']
						];
			echo json_encode($message, JSON_NUMERIC_CHECK);
			
		} else {
			throw new Exception('Файл не найден');
		}

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Обновляем Views
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'update_views':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									   c.id AS channel_id
								  FROM followers AS f
									   JOIN
									   posts AS p ON p.channel_id = f.channel_id
									   JOIN
									   channels AS c ON c.id = p.channel_id
								 WHERE p.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($post_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE followers SET view_id=? WHERE user_id=? AND channel_id=?');
		$stmt->execute(array($post_id, $user_id, $exist[0]['channel_id']));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Удаление поста
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									   p.user_id,
									   c.user_id as admin_id,
									   c.type
								  FROM followers AS f
									   JOIN
									   posts AS p ON p.channel_id = f.channel_id
									   JOIN
									   channels AS c ON c.id = p.channel_id
								 WHERE p.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($post_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# Проверки безопасности
		if ($exist[0]['type'] == 3 and $exist[0]['admin_id'] != $user_id) {
			
			# Проверки безопасности
			if ($exist[0]['user_id'] != $user_id) {
				throw new Exception('У Вас нет доступа');
			}
			
		} elseif ($exist[0]['type'] == 1 or $exist[0]['type'] == 2) {
			
			# Проверки безопасности
			if ($exist[0]['user_id'] != $user_id) {
				throw new Exception('У Вас нет доступа');
			}
			
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Получаем файлы сообщения в БД
		$sth = $dbh->prepare("SELECT * FROM attaches WHERE post_id = ?;");
		$sth->execute(array($post_id));
					
		# Обработка полученных данных
		$attaches = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Удаляем файлы с сервера
		if (count($attaches) > 0) {
			$type_img = array('image/jpeg','image/png');
			foreach ($attaches as $a) {
				unlink('../attach/'.$a['salt'].'_'.$a['id'].'.smh');
				if (in_array($a['mime'], $type_img)) {
					unlink('../attach/'.$a['salt'].'_'.$a['id'].'.thumb.jpg');
				}
			}
		}

		# Запрос к БД
		$stmt = $dbh->prepare('DELETE FROM posts WHERE id=?;');
		$stmt->execute(array($post_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Like post
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'like_post':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id
								  FROM followers AS f
								  JOIN posts as p ON p.channel_id = f.channel_id
								 WHERE p.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($post_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Проверка на существование лайка к этой записи
		$sth = $dbh->prepare("SELECT id FROM likes WHERE post_id = ? AND user_id = ?;");
		$sth->execute(array($post_id, $user_id));		
		$likes = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Если лайк не установлен то вписываем его
		if (count($likes) == 0) {
			$stmt = $dbh->prepare('INSERT INTO likes (post_id, user_id, date_create) VALUES (?, ?, ?)');
			$stmt->execute(array($post_id, $user_id, $date_create));			
		} else {
			$stmt = $dbh->prepare('DELETE FROM likes WHERE post_id=? AND user_id = ?;');
			$stmt->execute(array($post_id, $user_id));
		}
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	

default:
break;


}


	# Закрываем соединение с базой
	$dbh = null;
	die();
	

?>