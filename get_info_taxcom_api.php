<?php
/*
Скрипт получения основной информации о кассовых аппарата через API ОФД Такском (Taxcom), для занесения данных в SQL таблицу и использования их на своем сайте.
Автор: Heavenanvil
https://github.com/Heavenanvil
*/

set_time_limit(60);  // Лимит времени выполнения операции (в секундах)

// Настройки подключения к ОФД
$login = 'login@yourdomain.com';          // Ваш логин
$password = 'YouRPa$$w0rd';               // Ваш пароль (у вашей учётной записи на сайте taxcom.ru должны быть права на работу с API)
$IntegratorID = '********-****-****-****-************'; // Ваш Интегратор ID (получать у TAXCOM'а через почту support@taxcom.ru, либо vip_support@taxcom.ru)
$proxy = '10.40.80.00:1234';              // Настройки прокси, если используется (IP:port, например 10.20.30.40:1234

//Настройки подключения SQL-базы
$db_host = "localhost";   // Хост (сервер)
$db_name = "your_db_name"; // Имя базы
$db_user = "your_db_user"; // Имя пользователя базы
$db_pass = "your_db_pass"; // Пароль пользователя базы
$table_name = "db_kassa";  // Имя таблицы, в которую вносятся значения
// Еще обращаем внимание на 80 и 111 строку в коде.

$total_count = 0;

// Попытка подключения в SQL-базе
try
{
	$db_connect = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
}
catch (mysqli_sql_exception $e)
{
	$message = "Не удается подключиться к базе: " . mysqli_connect_error();
	die($message);
}

// Авторизация // Login
$url_Login = 'https://api-lk-ofd.taxcom.ru/API/v2/Login';
$jsonData_Login='{"login": "' . $login . '", "password" : "' . $password . '"}';
$ch_Login = curl_init($url_Login);

if ((isset($proxy)) AND ($proxy != "") AND ($proxy != NULL))
{
	curl_setopt($ch_Login, CURLOPT_PROXY, $proxy);
}
curl_setopt($ch_Login, CURLOPT_POST, 1);
curl_setopt($ch_Login, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_Login, CURLOPT_POSTFIELDS, $jsonData_Login);
curl_setopt($ch_Login, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Integrator-ID: ' . $IntegratorID . ''));
$TaxcomSessionToken=curl_exec($ch_Login);
curl_close($ch_Login);
$TaxcomSessionToken_json = json_decode($TaxcomSessionToken, true);

// Если удалось получить токен, определяем его в переменную
if (isset($TaxcomSessionToken_json['sessionToken']))
{
	$TaxcomSessionToken = $TaxcomSessionToken_json['sessionToken'];
}
else
{
	$message = "Не удалось получить «sessionToken». Сервер недоступен, либо неверный логин/пароль/Integrator-ID. <br>\n";
	die($message);
}

// Если токен получен успешно
if ((isset($TaxcomSessionToken)) AND ($TaxcomSessionToken != "") AND ($TaxcomSessionToken != NULL))
{
	
	// Список подразделений // DepartmentList
	$url_DepartmentList = 'https://api-lk-ofd.taxcom.ru/API/v2/DepartmentList';
	$ch_DepartmentList = curl_init($url_DepartmentList);
	if ((isset($proxy)) AND ($proxy != "") AND ($proxy != NULL))
	{
		curl_setopt($ch_DepartmentList, CURLOPT_PROXY, $proxy);
	}
	curl_setopt($ch_DepartmentList, CURLOPT_POST, 0);
	curl_setopt($ch_DepartmentList, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch_DepartmentList, CURLOPT_HTTPHEADER, array('Session-Token: ' . $TaxcomSessionToken ));
	$DepartmentList=curl_exec($ch_DepartmentList);
	curl_close($ch_DepartmentList);
	$DepartmentList_json = json_decode($DepartmentList, true);
	$department_id = $DepartmentList_json['records'][6]['id'];	// Получаем id подразделения "Южное тер. управление", возможно у вас будет другое, посмотреть можно через "var_dump(DepartmentList_json);"
	$department_id = mb_substr($department_id, 0, 64); // Здесь и далее, на всякий случай, обрезаем максимальную длину строки, для внесения в базу.
	
	// Если id подразделения успешно получен
	if ((isset($department_id)) AND ($department_id != "") AND ($department_id != NULL))
	{
		// Список торговых точек // OutletList
		$url_OutletList = 'https://api-lk-ofd.taxcom.ru/API/v2/OutletList?id=' . $department_id . '';
		$ch_OutletList = curl_init($url_OutletList);
		if ((isset($proxy)) AND ($proxy != "") AND ($proxy != NULL))
		{
			curl_setopt($ch_OutletList, CURLOPT_PROXY, $proxy);
		}
		curl_setopt($ch_OutletList, CURLOPT_POST, 0);
		curl_setopt($ch_OutletList, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch_OutletList, CURLOPT_HTTPHEADER, array('Session-Token: ' . $TaxcomSessionToken ));
		$OutletList=curl_exec($ch_OutletList);
		curl_close($ch_OutletList);
		$OutletList_json = json_decode($OutletList, true);
		
		$id_outlet_array = array_column($OutletList_json['records'],'id');	// Получаем массив из нужных id от торговых точек нашего подразделения
		$id_outlet_count = count($id_outlet_array);	// Получаем общее количество наших торговых точек
		
		
		// Если нужные торговые точки имеются
		if ($id_outlet_count > 0)
		{

			// Запускаем цикл операций для каждой торговой точки
			for ($i=0; $i<$id_outlet_count; $i++)
			{
				$id_outlet = $id_outlet_array[$i];
				$id_outlet = mb_substr($id_outlet, 0, 64);
				$timestamp = date("Y-m-d H:i:s", strtotime("+5 hour")); // Определяем текущее время и дату + корректируем часовой пояс
				
				// Если id торговой точки успешно получен
				if ((isset($id_outlet)) AND ($id_outlet != "") AND ($id_outlet != NULL))
				{
					// Список ККТ по торговой точке // KKTList
					$url_KKTList = 'https://api-lk-ofd.taxcom.ru/API/v2/KKTList?id=' . $id_outlet . '';
					$ch_KKTList = curl_init($url_KKTList);
					if ((isset($proxy)) AND ($proxy != "") AND ($proxy != NULL))
					{
						curl_setopt($ch_KKTList, CURLOPT_PROXY, $proxy);
					}
					curl_setopt($ch_KKTList, CURLOPT_POST, 0);
					curl_setopt($ch_KKTList, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch_KKTList, CURLOPT_HTTPHEADER, array('Session-Token: ' . $TaxcomSessionToken ));
					$KKTList=curl_exec($ch_KKTList);
					curl_close($ch_KKTList);
					$KKTList_json = json_decode($KKTList, true);
					
					$kktRegNumber_kkt_array = array_column($KKTList_json['records'],'kktRegNumber');	// Получаем массив из нужных регистрационных номеров от касс из каждой торговой точки
					$kktRegNumber_kkt_count = count($kktRegNumber_kkt_array);	// Получаем общее количество наших касс по каждой торговой точке
					
					// Если нужные кассы имеются
					if ($kktRegNumber_kkt_count > 0)
					{
						// Запускаем цикл операций для каждой кассы на торговой точке
						for ($j=0; $j<$kktRegNumber_kkt_count; $j++)
						{
							$id_kassa = $kktRegNumber_kkt_array[$j];
							$id_kassa = mb_substr($id_kassa, 0, 64);
							
							// Если id торговой точки успешно получен
							if ((isset($id_kassa)) AND ($id_kassa != "") AND ($id_kassa != NULL))
							{
								
								if (isset($KKTList_json['records'][$j]['kktRegNumber']))
								{
									$kktRegNumber = $KKTList_json['records'][$j]['kktRegNumber'];
								}
								else
								{
									$kktRegNumber = "";
								}
								
								// Список активных фискальных накопителей по ККТ // FnHistory
								$url_FnHistory = 'https://api-lk-ofd.taxcom.ru/API/v2/FnHistory?id=' . $id_outlet . '&kktRegNumber=' . $kktRegNumber . '&np=active';
								$ch_FnHistory = curl_init($url_FnHistory);
								if ((isset($proxy)) AND ($proxy != "") AND ($proxy != NULL))
								{
									curl_setopt($ch_FnHistory, CURLOPT_PROXY, $proxy);
								}
								curl_setopt($ch_FnHistory, CURLOPT_POST, 0);
								curl_setopt($ch_FnHistory, CURLOPT_RETURNTRANSFER, true);
								curl_setopt($ch_FnHistory, CURLOPT_HTTPHEADER, array('Session-Token: ' . $TaxcomSessionToken ));
								$FnHistory=curl_exec($ch_FnHistory);
								curl_close($ch_FnHistory);
								$FnHistory_json = json_decode($FnHistory, true);
								
								if (isset($FnHistory_json['records'][0]['fn']))
								{
									$fn = $FnHistory_json['records'][0]['fn'];
								}
								else
								{
									$fn = "";
								}
								
								// Сводные данные по ККТ // KKTInfo
								$url_KKTInfo = 'https://api-lk-ofd.taxcom.ru/API/v2/KKTInfo?fn=' . $fn . '';
								$ch_KKTInfo = curl_init($url_KKTInfo);
								if ((isset($proxy)) AND ($proxy != "") AND ($proxy != NULL))
								{
									curl_setopt($ch_KKTInfo, CURLOPT_PROXY, $proxy);
								}
								curl_setopt($ch_KKTInfo, CURLOPT_POST, 0);
								curl_setopt($ch_KKTInfo, CURLOPT_RETURNTRANSFER, true);
								curl_setopt($ch_KKTInfo, CURLOPT_HTTPHEADER, array('Session-Token: ' . $TaxcomSessionToken ));
								$KKTInfo=curl_exec($ch_KKTInfo);
								curl_close($ch_KKTInfo);
								$KKTInfo_json = json_decode($KKTInfo, true);
								
								// Если все необходимые данные успешно получены, определяем их в переменные
								if ($KKTInfo_json)
								{
									// reportDate	// Дата обновления
									if (isset($KKTInfo_json['reportDate']) AND ($KKTInfo_json['reportDate'] != "") AND ($KKTInfo_json['reportDate'] != NULL))
									{	
										$reportDate = $KKTInfo_json['reportDate']; 
									}
									else
									{
										$reportDate = "";
									}
									
									// fnRegDateTime // Дата регистрации ФН
									if (isset($KKTInfo_json['cashdesk']['fnRegDateTime']) AND ($KKTInfo_json['cashdesk']['fnRegDateTime'] != "") AND ($KKTInfo_json['cashdesk']['fnRegDateTime'] != NULL))
									{	
										$fnRegDateTime = $KKTInfo_json['cashdesk']['fnRegDateTime']; 
									} 
									else
									{	
										$fnRegDateTime = "";
									}
									
									// fnDuration // Срок действия ФН (в месяцах)
									if (isset($KKTInfo_json['cashdesk']['fnDuration']) AND ($KKTInfo_json['cashdesk']['fnDuration'] != "") AND ($KKTInfo_json['cashdesk']['fnDuration'] != NULL))
									{
										$fnDuration = $KKTInfo_json['cashdesk']['fnDuration'];
										$fnDuration_string = (string)$fnDuration;	// Переводим число в строку, "обрезаем" и снова переводим в число
										$fnDuration_string = mb_substr($fnDuration_string, 0, 3);
										$fnDuration_int = (int)$fnDuration_string;
										$fnDuration = $fnDuration_int;
									}
									else
									{
										$fnDuration = "";
									}
									
									// shiftStatus // Состояние смены
									// Close - Закрыта / Open - Открыта / NoData - Нет данных
									if (isset($KKTInfo_json['cashdesk']['shiftStatus']) AND ($KKTInfo_json['cashdesk']['shiftStatus'] != "") AND ($KKTInfo_json['cashdesk']['shiftStatus'] != NULL))
									{
										$shiftStatus = $KKTInfo_json['cashdesk']['shiftStatus'];
										$shiftStatus = mb_substr($shiftStatus, 0, 64);
									}
									else
									{
										$shiftStatus = "";
									}
									
									// cashdeskState // Состояние кассы	
									// Active – Подключена / Expires – Заканчивается оплата / Expired – Не оплачена / Inactive – Отключена пользователем / Activation – Подключение
									// Deactivation – Отключение / FNChange – Замена ФН / FNSRegistration /  Регистрация в ФНС /  FNSRegistrationError – Ошибка регистрации в ФНС
									if (isset($KKTInfo_json['cashdesk']['cashdeskState']) AND ($KKTInfo_json['cashdesk']['cashdeskState'] != "") AND ($KKTInfo_json['cashdesk']['cashdeskState'] != NULL))
									{
										$cashdeskState = $KKTInfo_json['cashdesk']['cashdeskState'];
										$cashdeskState = mb_substr($cashdeskState, 0, 64);
									}
									else
									{
										$cashdeskState = "";
									}
									
									// cashdeskEndDateTime // Срок действия ОФД
									if (isset($KKTInfo_json['cashdesk']['cashdeskEndDateTime']) AND ($KKTInfo_json['cashdesk']['cashdeskEndDateTime'] != "") AND ($KKTInfo_json['cashdesk']['cashdeskEndDateTime'] != NULL))
									{
										$cashdeskEndDateTime = $KKTInfo_json['cashdesk']['cashdeskEndDateTime'];
									}
									else
									{
										$cashdeskEndDateTime = "";
									}
									
									// fnState // Состояние ФН
									// Active - Активный / Expires - Истекает / Expired - Истек
									if (isset($KKTInfo_json['cashdesk']['fnState']) AND ($KKTInfo_json['cashdesk']['fnState'] != "") AND ($KKTInfo_json['cashdesk']['fnState'] != NULL))
									{
										$fnState = $KKTInfo_json['cashdesk']['fnState'];
										$fnState = mb_substr($fnState, 0, 64);
									}
									else
									{
										$fnState = "";
									}
									
									// fnEndDateTime // Дата окончания ФН
									if (isset($KKTInfo_json['cashdesk']['fnEndDateTime']) AND ($KKTInfo_json['cashdesk']['fnEndDateTime'] != "") AND ($KKTInfo_json['cashdesk']['fnEndDateTime'] != NULL))
									{
										$fnEndDateTime = $KKTInfo_json['cashdesk']['fnEndDateTime'];
									}
									else
									{
										$fnEndDateTime = "";
									}
									
									// lastDocumentState // Состояние последнего документа
									// OK - Хорошо / Warning - Предупреждение / Problem - Проблема
									if (isset($KKTInfo_json['cashdesk']['lastDocumentState']) AND ($KKTInfo_json['cashdesk']['lastDocumentState'] != "") AND ($KKTInfo_json['cashdesk']['lastDocumentState'] != NULL))
									{
										$lastDocumentState = $KKTInfo_json['cashdesk']['lastDocumentState'];
										$lastDocumentState = mb_substr($lastDocumentState, 0, 64);
									}
									else
									{
										$lastDocumentState = "";
									}
									
									// lastDocumentDateTime // Дата последнего документа
									if (isset($KKTInfo_json['cashdesk']['lastDocumentDateTime']) AND ($KKTInfo_json['cashdesk']['lastDocumentDateTime'] != "") AND ($KKTInfo_json['cashdesk']['lastDocumentDateTime'] != NULL))
									{
										$lastDocumentDateTime = $KKTInfo_json['cashdesk']['lastDocumentDateTime']; 
									} 
									else
									{
										$lastDocumentDateTime = "";
									}
									
									// name // Имя кассы
									if (isset($KKTInfo_json['cashdesk']['name']) AND ($KKTInfo_json['cashdesk']['name'] != "") AND ($KKTInfo_json['cashdesk']['name'] != NULL))
									{
										$name = $KKTInfo_json['cashdesk']['name'];
										$name = mb_substr($name, 0, 255);
									}
									else
									{
										$name = "";
									}
									
									// kktRegNumber // Регистрационный номер ККТ
									if (isset($KKTInfo_json['cashdesk']['kktRegNumber']) AND ($KKTInfo_json['cashdesk']['kktRegNumber'] != "") AND ($KKTInfo_json['cashdesk']['kktRegNumber'] != NULL))
									{
										$kktRegNumber = $KKTInfo_json['cashdesk']['kktRegNumber'];
										$kktRegNumber = mb_substr($kktRegNumber, 0, 64);
									}
									else
									{
										$kktRegNumber = "";
									}
									
									// kktFactoryNumber // Заводской номер ККТ
									if (isset($KKTInfo_json['cashdesk']['kktFactoryNumber']) AND ($KKTInfo_json['cashdesk']['kktFactoryNumber'] != "") AND ($KKTInfo_json['cashdesk']['kktFactoryNumber'] != NULL))
									{
										$kktFactoryNumber = $KKTInfo_json['cashdesk']['kktFactoryNumber'];
										$kktFactoryNumber = mb_substr($kktFactoryNumber, 0, 64);
									}
									else
									{
										$kktFactoryNumber = "";
									}
									
									// kktModelName // Модель кассы
									if (isset($KKTInfo_json['cashdesk']['kktModelName']) AND ($KKTInfo_json['cashdesk']['kktModelName'] != "") AND ($KKTInfo_json['cashdesk']['kktModelName'] != NULL))
									{
										$kktModelName = $KKTInfo_json['cashdesk']['kktModelName'];
										$kktModelName = mb_substr($kktModelName, 0, 64);
									}
									else
									{
										$kktModelName = "";
									}
									
									// fnFactoryNumber // Заводской номер ФН
									if (isset($KKTInfo_json['cashdesk']['fnFactoryNumber']) AND ($KKTInfo_json['cashdesk']['fnFactoryNumber'] != "") AND ($KKTInfo_json['cashdesk']['fnFactoryNumber'] != NULL))
									{
										$fnFactoryNumber = $KKTInfo_json['cashdesk']['fnFactoryNumber'];
										$fnFactoryNumber = mb_substr($fnFactoryNumber, 0, 64);
									}
									else
									{
										$fnFactoryNumber = "";
									}
									
									// address // Адрес кассы
									if (isset($KKTInfo_json['cashdesk']['outlet']['address']) AND ($KKTInfo_json['cashdesk']['outlet']['address'] != "") AND ($KKTInfo_json['cashdesk']['outlet']['address'] != NULL))
									{
										$address = $KKTInfo_json['cashdesk']['outlet']['address'];
										$address = mb_substr($address, 0, 255);
									} 
									else
									{	
										if (isset($KKTInfo_json['cashdesk']['outlet']['name']) AND ($KKTInfo_json['cashdesk']['outlet']['name'] != "") AND ($KKTInfo_json['cashdesk']['outlet']['name'] != NULL))
										{
											$address = $KKTInfo_json['cashdesk']['outlet']['name'];
											$address = mb_substr($address, 0, 255);
										}
										else
										{
											$address = "";
										}
									}
									
									$total_count++;
										

									// Проверяем существует ли нужная таблица в БД и если нет, то создаём её.
									$query_create = "CREATE TABLE IF NOT EXISTS `" . $table_name . "` (
									`id` INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
									`department_id` VARCHAR(64) DEFAULT NULL, 
									`outlet_id` VARCHAR(64) DEFAULT NULL, 
									`kassa_id` VARCHAR(64) DEFAULT NULL, 
									`reportDate` TIMESTAMP NULL DEFAULT NULL, 
									`fnRegDateTime` TIMESTAMP NULL DEFAULT NULL, 
									`fnDuration` INT(3) DEFAULT NULL, 
									`shiftStatus` VARCHAR(64) DEFAULT NULL, 
									`cashdeskState` VARCHAR(64) DEFAULT NULL, 
									`cashdeskEndDateTime` TIMESTAMP NULL DEFAULT NULL, 
									`fnState` VARCHAR(64) DEFAULT NULL, 
									`fnEndDateTime` TIMESTAMP NULL DEFAULT NULL, 
									`lastDocumentState` VARCHAR(64) DEFAULT NULL, 
									`lastDocumentDateTime` TIMESTAMP NULL DEFAULT NULL, 
									`name` VARCHAR(255) DEFAULT NULL, 
									`kktRegNumber` VARCHAR(64) DEFAULT NULL, 
									`kktFactoryNumber` VARCHAR(64) DEFAULT NULL,
									`kktModelName` VARCHAR(64) DEFAULT NULL, 
									`fnFactoryNumber` VARCHAR(64) DEFAULT NULL, 
									`address` VARCHAR(255) DEFAULT NULL, 
									`kassa_lastupdate` VARCHAR(64) DEFAULT NULL
									)";	

									$request_create = mysqli_query($db_connect, $query_create) or die(mysql_error() . " " . mysql_errno());		
									
									// Ищем такую кассу в базе
									$query_search = "SELECT * FROM `" . $table_name . "` WHERE `kassa_id`='" . $id_kassa . "'";
									$request_search = mysqli_query($db_connect, $query_search) or die(mysql_error() . " " . mysql_errno());
									$result_search = mysqli_fetch_array($request_search);
									mysqli_free_result($request_search);
									
									// Если касса найдена, то обновляем данные в базе 
									if (isset($result_search['kassa_id']))
									{
										$query_update = "UPDATE `" . $table_name . "` SET 
										`department_id`='" . $department_id . "',
										`outlet_id`='" . $id_outlet . "',
										`kassa_id`='" . $id_kassa . "',
										`reportDate`='" . $reportDate . "',
										`fnRegDateTime`='" . $fnRegDateTime . "',
										`fnDuration`='" . $fnDuration . "',
										`shiftStatus`='" . $shiftStatus . "',
										`cashdeskState`='" . $cashdeskState . "',
										`cashdeskEndDateTime`='" . $cashdeskEndDateTime . "',
										`fnState`='" . $fnState . "',
										`fnEndDateTime`='" . $fnEndDateTime . "',
										`lastDocumentState`='" . $lastDocumentState . "',
										`lastDocumentDateTime`='" . $lastDocumentDateTime . "',
										`name`='" . $name . "',
										`kktRegNumber`='" . $kktRegNumber . "',
										`kktFactoryNumber`='" . $kktFactoryNumber . "',
										`kktModelName`='" . $kktModelName . "',
										`fnFactoryNumber`='" . $fnFactoryNumber . "',
										`address`='" . $address . "',
										`kassa_lastupdate`='" . $timestamp . "'
										WHERE `kassa_id`='" . $id_kassa . "'";
										$request_update = mysqli_query($db_connect, $query_update) or die(mysql_error() . " " . mysql_errno());
										
										// Если запрос выполнен успешно
										if ($request_update)
										{
											echo "[" . $total_count . "] Касса <b>" . $name . "</b> успешно обновлена. <br>\n";		
										}
									}
									else
									{
										// Если касса НЕ найдена, то создаём новую запись в базе
										$query_insert = "INSERT INTO `" . $table_name . "` (
										`department_id`, 
										`outlet_id`, 
										`kassa_id`, 
										`reportDate`, 
										`fnRegDateTime`, 
										`fnDuration`, 
										`shiftStatus`, 
										`cashdeskState`, 
										`cashdeskEndDateTime`, 
										`fnState`, 
										`fnEndDateTime`, 
										`lastDocumentState`, 
										`lastDocumentDateTime`, 
										`name`, 
										`kktRegNumber`, 
										`kktFactoryNumber`, 
										`kktModelName`, 
										`fnFactoryNumber`, 
										`address`, 
										`kassa_lastupdate`
										) VALUES (
										'" . $department_id . "',
										'" . $id_outlet . "',
										'" . $id_kassa . "',
										'" . $reportDate . "',
										'" . $fnRegDateTime . "',
										'" . $fnDuration . "',
										'" . $shiftStatus . "',
										'" . $cashdeskState . "',
										'" . $cashdeskEndDateTime . "',
										'" . $fnState . "',
										'" . $fnEndDateTime . "',
										'" . $lastDocumentState . "',
										'" . $lastDocumentDateTime . "',
										'" . $name . "',
										'" . $kktRegNumber . "',
										'" . $kktFactoryNumber . "',
										'" . $kktModelName . "',
										'" . $fnFactoryNumber . "',
										'" . $address . "',
										'" . $timestamp . "'
										)";
										$request_insert = mysqli_query($db_connect, $query_insert) or die(mysql_error() . " " . mysql_errno());
										
										// Если запрос выполнен успешно
										if ($request_insert)
										{
											echo "[" . $total_count . "] Касса <b>" . $name . "</b> успешно добавлена в базу. <br>\n";	
										}
									}					
								}
							}
						}
					}
				}
			}
			mysqli_close($db_connect);
			$message = "Операция завершена. <br>\n";
			die($message);
		}
		else
		{
			$message = "Нет доступных касс для получения данных. <br>\n";
			die($message);
		}
	}
	else
	{
		$message = "Не удалось получить id подразделения. <br>\n";
		die($message);
	}
}
?>
