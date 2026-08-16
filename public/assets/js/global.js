if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js')
            .catch(error => {
                console.error('Error al registrar Service Worker:', error);
            });
    });
}

function CerrarSesion(base_url = null) {
    $.ajax({
            url: `${typeof homeURL != 'undefined' ? homeURL : base_url}/api/auth/logout`,
            type: 'post',
		        dataType: "json",
            success: function(response) {
              console.log(response);
                if(response.status == 'OK')
                    window.location.href = `${typeof homeURL != 'undefined' ? `${homeURL}` : `${base_url}` ?? ''}/autenticacion/`;
                else
                    ShowSweetAlert('error', '¡Ocurrio un Error!', 'Ocurrio un error al intentar realizar la autenticacion.', 'Entendido');	
            },
            error: function(XMLHttpRequest, textStatus, errorThrown) {
                console.log('STATUS:', textStatus);
                console.log('ERROR:', errorThrown);
                console.log('RESPONSE TEXT:', XMLHttpRequest.responseText);

                alert(XMLHttpRequest.responseText);
            }  
    });
}

// Datepicker.locales.es = {
//   days: ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"],
//   daysShort: ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"],
//   daysMin: ["Do", "Lu", "Ma", "Mi", "Ju", "Vi", "Sa"],
//   months: [
//     "Enero", "Febrero", "Marzo", "Abril",
//     "Mayo", "Junio", "Julio", "Agosto",
//     "Septiembre", "Octubre", "Noviembre", "Diciembre"
//   ],
//   monthsShort: ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"],
//   today: "Hoy",
//   clear: "Limpiar",
//   titleFormat: "MM yyyy", // título arriba (ej: Abril 2026)
//   weekStart: 0 // 0 = domingo, 1 = lunes
// };

function initDatePicker(id, onChangeCallback, selectToday = false) {
  const elem = document.getElementById(id);
  if (!elem) return null;

  if (elem.datepicker) {
    return elem.datepicker;
  }

  let hoy = null;
  if(selectToday) {
    hoy = new Date();

    const dd = String(hoy.getDate()).padStart(2, '0');
    const mm = String(hoy.getMonth() + 1).padStart(2, '0'); // Enero es 0
    const yyyy = hoy.getFullYear();
    elem.value = `${dd}/${mm}/${yyyy}`;
  }

  const dp = new Datepicker(elem, {
    language: 'es',
    format: 'dd/mm/yyyy',
    nextArrow: '<i class="uil uil-angle-right-b"></i>',
    prevArrow: '<i class="uil uil-angle-left-b"></i>',
    
    defaultViewDate: hoy
  });

  // Escuchar el evento de selección de fecha
  elem.addEventListener('changeDate', function(e) {
    if (onChangeCallback && typeof onChangeCallback === 'function') {
      // e.detail.date contiene el objeto de fecha seleccionado
      onChangeCallback(e.detail.date, elem.value);
    }
  });

  return dp;
}

function clearDatePicker(dp) {
    dp.setDate({ clear: true });

    dp.pickerElement.classList.add('datepicker-empty');
}

function datePickerFormattedDate(dp) {
  // 1. Obtenemos el objeto Date nativo que tiene seleccionado el datepicker
  const fecha = dp.getDate();

  // Si no hay ninguna fecha seleccionada, retornamos null o vacío
  if (!fecha) return null;

  // 2. Extraemos el año, el mes y el día
  const yyyy = fecha.getFullYear();
  
  // Agregamos un cero a la izquierda si el mes o el día son menores a 10
  const mm = String(fecha.getMonth() + 1).padStart(2, '0'); // getMonth() va de 0 a 11
  const dd = String(fecha.getDate()).padStart(2, '0');

  // 3. Unimos las piezas con guiones
  return `${yyyy}-${mm}-${dd}`;
}

function refreshSelectOption(id) {
  const selectEl = document.getElementById(id);
  const selectInstance = te.Select.getInstance(selectEl);
  if (selectInstance) {
      selectInstance.dispose();
  }
  new te.Select(selectEl);
}

function ActiveMenu(l) {
  const links = document.querySelectorAll(`a[href="${l}"]`);

  links.forEach(link => {
      link.classList.add('active');
  });
}

function escapeHTML(str) {
	return str
	  .replace(/&/g, "&amp;")
	  .replace(/'/g, "\\'")
	  .replace(/"/g, '&quot;')
	  .replace(/</g, "&lt;")
	  .replace(/>/g, "&gt;");
}

function formatTimeTo12h(time24) {
    if (!time24) return '';

    const [hoursStr, minutes] = time24.split(':');
    let hours = parseInt(hoursStr, 10);

    const period = hours >= 12 ? 'PM' : 'AM';

    hours = hours % 12;
    hours = hours === 0 ? 12 : hours; // 0 → 12

    return `${hours}:${minutes} ${period}`;
}

function callUrlTimer(seconds, span, url) {
    document.querySelector(`.${span}`).textContent = `${seconds} ${seconds == 1 ? 'segundo' : 'segundos'}`;
    const intervalId = setInterval(() => {
      seconds -= 1;

        if (seconds < 0) {
            clearInterval(intervalId);
            window.open(url, '_self');
        } else {
          document.querySelector(`.${span}`).textContent = `${seconds} ${seconds == 1 ? 'segundo' : 'segundos'}`;
        }
    }, 1000);
}

function getCaretPosition(element) {
    const selection = window.getSelection();
    if (!selection.rangeCount) return 0;

    const range = selection.getRangeAt(0);
    const preRange = range.cloneRange();

    preRange.selectNodeContents(element);
    preRange.setEnd(range.endContainer, range.endOffset);

    return preRange.toString().length;
}

function setCaretPosition(element, position) {
    const range = document.createRange();
    const selection = window.getSelection();

    if (!element.firstChild) return;

    range.setStart(element.firstChild, Math.min(position, element.firstChild.length));
    range.collapse(true);

    selection.removeAllRanges();
    selection.addRange(range);
}

function setHeaderCartIconEmpty() {
	  $('#btn-header-menu-cart').html(`<i class="uil uil-shopping-cart-alt"></i>`);
}

function setHeaderCartIcon(qty = 1) {
	  $('#btn-header-menu-cart').html(`<i class="uil uil-shopping-cart-alt"></i>
                                    <span class="text-[11px] leading-[12px] absolute top-2 start-[15px] transform -translate-y-1/2 px-[4.50px] py-[1px] bg-danger border-2 border-white dark:border-gray-800 rounded-[15px] inline-flex items-center justify-center text-white dark:text-dark">${qty}</span>`);
}