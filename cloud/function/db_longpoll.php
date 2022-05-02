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
# Longpoll
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


switch ($action) {
case 'longpoll':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$_channel = $_POST['channel'];
		$_channels = $_POST['channels'];
		$_points = $_POST['points'];
		$live_time = time();
		
		# Сессия lonpoll
		$longpoll_id = gen_id();
		$stmt = $dbh->prepare('UPDATE users SET longpoll_id=? WHERE id=?');
		$stmt->execute(array($longpoll_id, $user_id));
		
		# Запускаем цикл
		while (true) {

			# Запрос к БД
			$sth = $dbh->prepare("SELECT longpoll_id FROM users WHERE id = ?;");
			$sth->execute(array($user_id));
			$_longpoll_id = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			# проверяем время коннекта к БД и id сессии longpoll
			if ((time() - $live_time) >= 58) {
				throw new Exception('success');
			} elseif ($longpoll_id != $_longpoll_id[0]['longpoll_id']) {
				throw new Exception('deprecated');
			}
		
			# Запрос к БД
			$sth = $dbh->prepare("SELECT c.*,
								   (CASE WHEN c.type = 1 THEN (
										   SELECT MAX(last_action) AS last_action
											 FROM (
													  SELECT date_login AS last_action
														FROM users
													   WHERE id = (
																	  SELECT user_id AS id
																		FROM followers
																	   WHERE user_id <> f.user_id AND 
																			 channel_id = C.id
																  )
													  UNION
													  SELECT MAX(date_create) AS last_action
														FROM posts
													   WHERE user_id = (
																		   SELECT user_id AS id
																			 FROM followers
																			WHERE user_id <> f.user_id AND 
																				  channel_id = C.id
																	   )
													  UNION
													  SELECT MAX(date_create) AS last_action
														FROM likes
													   WHERE user_id = (
																		   SELECT user_id AS id
																			 FROM followers
																			WHERE user_id <> f.user_id AND 
																				  channel_id = C.id
																	   )
													  UNION
													  SELECT MAX(date_create) AS last_action
														FROM comments
													   WHERE user_id = (
																		   SELECT user_id AS id
																			 FROM followers
																			WHERE user_id <> f.user_id AND 
																				  channel_id = C.id
																	   )
												  )
									   )
								   ELSE NULL END) AS follower_status,
								   (CASE WHEN c.type = 1 THEN (
										   SELECT (CASE WHEN block = 0 THEN 0 ELSE 1 END) 
											 FROM users
											WHERE id = (
														   SELECT user_id AS id
															 FROM followers
															WHERE user_id <> f.user_id AND 
																  channel_id = C.id
													   )
									   )
								   END) AS is_block,
								   (CASE WHEN c.type = 1 THEN (
										   SELECT (CASE WHEN display_name = 0 THEN (first_name || ' ' || last_name) ELSE nickname END) 
											 FROM users
											WHERE id = (
														   SELECT user_id AS id
															 FROM followers
															WHERE user_id <> f.user_id AND 
																  channel_id = C.id
													   )
									   )
								   ELSE C.name END) AS display_name,
								   (CASE WHEN c.type = 1 THEN (
										   SELECT avatar
											 FROM users
											WHERE id = (
														   SELECT user_id AS id
															 FROM followers
															WHERE user_id <> f.user_id AND 
																  channel_id = C.id
													   )
									   )
								   ELSE C.avatar END) AS display_avatar,
								   (CASE WHEN c.type = 1 THEN (
										   SELECT id
											 FROM users
											WHERE id = (
														   SELECT user_id AS id
															 FROM followers
															WHERE user_id <> f.user_id AND 
																  channel_id = C.id
													   )
									   )
								   ELSE C.id END) AS display_id,
								   (
									   SELECT COALESCE(MAX(id), 0) 
										 FROM posts
										WHERE channel_id = c.id AND 
											  posts.access IS NOT 1
								   )
								   AS last_post_id,
								   (CASE WHEN (
												  SELECT MAX(id) 
													FROM posts
												   WHERE channel_id = c.id
											  )
							>          f.access_id THEN ( (
										   SELECT post
											 FROM posts
											WHERE channel_id = c.id AND 
												  id = (
														   SELECT MAX(id) 
															 FROM posts
															WHERE channel_id = c.id AND 
																  posts.access IS NOT 1
													   )
									   )
									   ) ELSE NULL END) AS last_post,
								   (
									   SELECT COUNT(id) 
										 FROM posts
										WHERE channel_id = c.id AND 
											  id > f.view_id AND 
											  posts.access IS NOT 1
								   )
								   AS new_post,
								   (CASE WHEN (
										   SELECT date_create
											 FROM posts
											WHERE channel_id = c.id AND 
												  id = (
														   SELECT MAX(date_create) 
															 FROM posts
															WHERE channel_id = c.id
													   )
									   )
									   IS NOT NULL THEN date_create ELSE c.date_create END) AS display_date,
								   (
									   SELECT visible
										 FROM followers
										WHERE user_id = c.user_id AND 
											  channel_id = c.id
								   )
								   AS visible,
								   (
									   SELECT COUNT(id) 
										 FROM followers
										WHERE channel_id = c.id
								   )
								   AS followers,
								   (
									   SELECT COUNT(a.id) 
										 FROM attaches AS a
											  JOIN
											  posts AS p ON p.id = a.post_id
										WHERE p.channel_id = c.id
								   )
								   AS files,
								   (
									   SELECT COUNT(id) 
										 FROM followers
										WHERE channel_id = c.id AND 
											  access = 1
								   )
								   AS access,
								   (
									   SELECT COUNT(id) 
										 FROM posts
										WHERE channel_id = c.id AND 
											  access = 1
								   )
								   AS access_posts,
								   f.view_id AS last_view_id
							  FROM channels AS c
								   JOIN
								   followers AS f ON f.channel_id = c.id
							 WHERE f.user_id = ? AND 
								   c.block = 0 AND 
								   f.visible = 1
							 ORDER BY last_post_id DESC;
							");
			$sth->execute(array($user_id));

			# Орабатываем запрос
			$data = $sth->fetchAll(PDO::FETCH_ASSOC);

			# ------------------------------------------------------------------------------------------------------------------------------------------------------------

			# Обрабатываем данные
			if (count($data) > 0) {

				# Обрабатываем параметры
				foreach ($data as $d) {

					# Если диалог с флагом шифрования
					if ($d['last_post']) {
						if ($d['secure']) {
							$d['last_post'] = $cypher->decrypt(base64_decode($d['last_post']));
						}
						# обрезаем строку
						$d['last_post'] = mb_substr($d['last_post'], 0, 40);
					}
					
					# set data
					$channels[$d['id']]  = $d;
					
					# Обрабатываем параметры
					if (!empty($_channel['id'])) {
					
				
						# Обрабатываем параметры
						if (($d['id'] == $_channel['id']) and ($d['last_post_id'] > $_channel['last_view_id'])) {
							
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
													   p.id > ? AND
													   p.access = ?
												 ORDER BY p.id DESC;");
							$sth->execute(array($user_id, $user_id, $d['id'], $_channel['last_view_id'], intval($_channel['private_posts'])));
							$db_posts = $sth->fetchAll(PDO::FETCH_ASSOC);
							$posts = [];
						
							# get attaches
							if (count($db_posts) > 0) {
								foreach ($db_posts as $p) {
										
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
									$posts[$p['id']] = $p;
									$posts[$p['id']]['attaches'] = $attaches;
									$posts[$p['id']]['avatar_likes'] = $avatar_likes;
									$posts[$p['id']]['avatar_comments'] = $avatar_comments;
										
								}
							}
									
						}
				
						# Обрабатываем параметры
						$new['posts'] = $posts;
						$new['id'] = $_channel['id'];
					
					}
					
				}

				# ------------------------------------------------------------------------------------------------------------------------------------------------------------

				# Проверка на изменения в записях
				if (isset($_points) && is_array($_points)) {
					
					# get id posts
					$points_ids = [];
					foreach ($_points as $p) {
						$points_ids[] = $p['id'];
					}
					
					# bind parameters
					$in = str_repeat('?,', count($points_ids)-1) . '?';
					
					# Запрос к БД
					$sth = $dbh->prepare("SELECT p.id,
											   p.date_edited,
											   (CASE WHEN c.type = 1 THEN (
													   SELECT view_id
														 FROM followers
														WHERE user_id IS NOT p.user_id AND 
															  channel_id = c.id
												   )
											   ELSE NULL END) AS read_this,
											   (
												   SELECT id
													 FROM likes
													WHERE post_id = p.id AND 
														  user_id = $user_id
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
														  user_id = $user_id
											   )
											   AS my_favorite
										  FROM posts AS p
											   LEFT JOIN
											   channels AS c ON c.id = p.channel_id
										 WHERE p.id IN ($in)
										 ORDER BY p.id DESC;");
					$sth->execute($points_ids);
					$db_points = $sth->fetchAll(PDO::FETCH_ASSOC);
					$db_points_ids = [];
					
					# compare points
					foreach ($_points as $p) {
						foreach ($db_points as $u) {
							if ($p['id'] == $u['id']) {
								if (($p['date_edited'] != $u['date_edited']) or ($p['read_this'] != $u['read_this']) or ($p['my_like'] != $u['my_like']) or ($p['count_comments'] != $u['count_comments']) or ($p['my_favorite'] != $u['my_favorite'])) {
									$db_points_ids[] = $p['id'];
								}
								break;
							}
						}
					}
					
					# is ids
					if (count($db_points_ids) > 0) {
					
						# bind parameters
						$in = str_repeat('?,', count($db_points_ids)-1) . '?';
						
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
															  user_id = $user_id
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
															  user_id = $user_id
												   )
												   AS my_favorite
											  FROM posts AS p
												   LEFT JOIN
												   channels AS c ON c.id = p.channel_id
											 WHERE p.id IN ($in) 
											 ORDER BY p.id DESC;");
						$sth->execute($db_points_ids);
						$db_posts = $sth->fetchAll(PDO::FETCH_ASSOC);
						$updates = [];
						
						# get attaches
						if (count($db_posts) > 0) {
							foreach ($db_posts as $p) {
									
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
														   AS reply_post
													  FROM comments AS c
													 WHERE post_id = ?;");
								$sth->execute(array($p['id']));
								$comments = $sth->fetchAll(PDO::FETCH_ASSOC);
								
								# set data
								$updates[$p['id']] = $p;
								$updates[$p['id']]['attaches'] = $attaches;
								$updates[$p['id']]['comments'] = $comments;
								$updates[$p['id']]['avatar_likes'] = $avatar_likes;
								$updates[$p['id']]['avatar_comments'] = $avatar_comments;
									
							}
						}
						
					}
					
					# ------------------------------------------------------------------------------------------------------------------------------------------------------------
					
				}
				
			} else {
				$_channels = null;
			}
			
			# ------------------------------------------------------------------------------------------------------------------------------------------------------------
			
			# compare points
			if (!empty($updates)) {
				break;
			}
			
			# compare data
			if ($_channels != $channels) {
				break;
			}
			
			# reset data
			$data = [];
			$channels = [];
			sleep(1);
		
		}
		
		# Выводим сообщение
		$message = ['status' => 'success',
					'channels' => $channels,
					'new' => $new,
					'updates' => $updates,
					'longpoll_id' => $longpoll_id
				   ];
		echo json_encode($message, JSON_NUMERIC_CHECK);
		
		# Завершаем работу longpoll
		$dbh = null;
		die();

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage(), 'longpoll_id' => $longpoll_id];
		echo json_encode($message, JSON_NUMERIC_CHECK);
		
		# Завершаем работу longpoll
		$dbh = null;
		die();
		
	}


default:
break;


}


	# Закрываем соединение с базой
	$dbh = null;
	die();
	

?>