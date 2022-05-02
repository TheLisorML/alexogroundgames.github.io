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
# Получаем комментарии
#---------------------------------------------------------------------------------------------------------------------------------------------------------------

	
switch ($action) {
case 'create_comment':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$post_id = (int) $_POST['post_id'];
		$comment = $_POST['comment'];
		$reply_to = $_POST['reply_to'];
		$date_create = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($post_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if (empty($reply_to)) {
			$reply_to = NULL;
		}
		
		# Проверяем значения
		if ($comment == "") {
			throw new Exception('Введите комментарий');
		}
		
		# Проверяем значения
		if (mb_strlen($comment, 'utf-8') > 16834) {
			throw new Exception('Слишком длинный текст');
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
		
		# Запрос к БД
		$stmt = $dbh->prepare('INSERT INTO comments (user_id, post_id, text, reply_to, date_create) VALUES (?, ?, ?, ?, ?);');
		$stmt->execute(array($user_id, $post_id, $comment, $reply_to, $date_create));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Загрузка комментария для редактирования
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'do_edit_comment':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$comment_id = (int) $_POST['comment_id'];
		
		# Проверяем значения
		if (empty($comment_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									 c.user_id
								  FROM followers AS f
								  JOIN posts as p ON p.channel_id = f.channel_id
								  JOIN comments AS c ON c.post_id = p.id
								 WHERE c.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($comment_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# Проверки безопасности
		if ($exist[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$sth = $dbh->prepare("SELECT * FROM comments WHERE id = ?;");
		$sth->execute(array($comment_id));
		$comment = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Выводим сообщение
		$message = ['status' => 'success', 'text' => $comment[0]['text']];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}
	
	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Сохраняем изменения в комментарии
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'edit_comment':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$comment_id = (int) $_POST['comment_id'];
		$text = $_POST['text'];
		$date_edited = date("Y-m-d H:i:s");
		
		# Проверяем значения
		if (empty($comment_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# Проверяем значения
		if ($text == "") {
			throw new Exception('Введите комментарий');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									 c.user_id
								  FROM followers AS f
								  JOIN posts as p ON p.channel_id = f.channel_id
								  JOIN comments AS c ON c.post_id = p.id
								 WHERE c.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($comment_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# Проверки безопасности
		if ($exist[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД
		$stmt = $dbh->prepare('UPDATE comments SET text=?, date_edited=? WHERE id=?');
		$stmt->execute(array($text, $date_edited, $comment_id));
		
		# Выводим сообщение
		$message = ['status' => 'success'];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	} catch(Exception $e) {

		# Выводим сообщение
		$message = ['status' => $e->getMessage()];
		echo json_encode($message, JSON_NUMERIC_CHECK);

	}

	
#---------------------------------------------------------------------------------------------------------------------------------------------------------------
# Удаление комментария
#---------------------------------------------------------------------------------------------------------------------------------------------------------------


break;
case 'delete_comment':


	# Проверяем данные на соответствие
	try {

		# Устанавливаем значения
		$comment_id = (int) $_POST['comment_id'];
		
		# Проверяем значения
		if (empty($comment_id)) {
			throw new Exception('Ошибка обработки запроса');
		}
		
		# ---------------------------------------------------------------------------------------
		
		# Запрос к БД для проверки безопасности
		$sth = $dbh->prepare("SELECT f.id,
									 c.user_id
								  FROM followers AS f
								  JOIN posts as p ON p.channel_id = f.channel_id
								  JOIN comments AS c ON c.post_id = p.id
								 WHERE c.id = ? AND 
									   f.user_id = ?;");
		$sth->execute(array($comment_id, $user_id));

		# Обрабатываем данные
		$exist = $sth->fetchAll(PDO::FETCH_ASSOC);
		
		# Проверки безопасности
		if (count($exist) == 0) {
			throw new Exception('У Вас нет доступа');
		}
		
		# Проверки безопасности
		if ($exist[0]['user_id'] != $user_id) {
			throw new Exception('У Вас нет доступа');
		}
		
		# ---------------------------------------------------------------------------------------

		# Запрос к БД
		$stmt = $dbh->prepare('DELETE FROM comments WHERE id=?;');
		$stmt->execute(array($comment_id));
		
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