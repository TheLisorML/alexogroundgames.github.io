
function replaceAll(text, search, replace){
    return text.split(search).join(replace);
}

function urlDecode(str){
	var result = decodeURI(str);
	result = replaceAll(result, '#', '%23');
	result = replaceAll(result, '+', '%2B');
	return result;
}

function showError(text){
	alert(text);
}


function buyDonat(){

	var accountName = $(`#donat-login`).val();

	var serverId = $(`#complect-server`).val();

    var sum = parseInt($('#donat-sum').val());

    if(isNaN(sum)){
        return showError('Неверно указана сумма');
    }

    if(sum < 1){
        return showError('Минимальная сумма - 1 руб');
    }

	if(!accountName){
		return showError('Вы не заполнили все поля');
	}

	if( ! (accountName && sum) ){ return false; }

	$.ajax({
		type: 'GET',
		url: `checkaccount.php?name=${urlDecode(accountName)}&serverid=${serverId}`,
		success: function(data){
			console.log(data);
			var response = JSON.parse(data);
			if(response.accountExists){
				// location.href = `https://unitpay.ru/pay/?sum=${sum}&account=DONAT;${urlDecode(accountName)};${serverId}&desc=Покупка%20валюты%20RAGE`;
				location.href = `/pay_redirect.php/=?sum=${sum}&account=DONAT;${urlDecode(accountName)};${serverId}&desc=Покупка%20валюты%20RAGE`;
			} else {
				showError('Аккаунт не найден');
			}
		},
		error: function(){
			showError('Неизвестная ошибка');
		},
	});
}

