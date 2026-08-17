// JavaScript Document

var homeURL;

function InitializeValues(home) {
	homeURL = home;
  if(callBack != null && callBack != '') {
    processCallBack(callBack);
  }
}

function processCallBack(callBack) {
  switch(callBack) {
    case 'missingInfo':
      ShowToastMessage('Falta Información', 'error');
      break;
    case 'deniedAccess':
      ShowToastMessage('Acceso denegado', 'error');
      break;
  }
}