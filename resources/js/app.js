import './bootstrap';
import '../css/app.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import Aura from '@primeuix/themes/aura';
import App from './App.vue';
import router from './router';

import 'primeicons/primeicons.css';

import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';

const app = createApp(App);

app.use(createPinia());
app.use(router);
app.use(ToastService);
app.use(ConfirmationService);
app.use(PrimeVue, {
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: '.dark',
        },
    },
    locale: {
        startsWith: 'Empieza con',
        contains: 'Contiene',
        notContains: 'No contiene',
        endsWith: 'Termina con',
        equals: 'Igual a',
        notEquals: 'Distinto de',
        noFilter: 'Sin filtro',
        lt: 'Menor que',
        lte: 'Menor o igual que',
        gt: 'Mayor que',
        gte: 'Mayor o igual que',
        dateIs: 'La fecha es',
        dateIsNot: 'La fecha no es',
        dateBefore: 'Fecha anterior a',
        dateAfter: 'Fecha posterior a',
        clear: 'Limpiar',
        apply: 'Aplicar',
        matchAll: 'Coincidir con todos',
        matchAny: 'Coincidir con cualquiera',
        addRule: 'Añadir condición',
        removeRule: 'Eliminar condición',
        choose: 'Seleccionar',
        cancel: 'Cancelar',
        dayNames: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
        dayNamesShort: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
        dayNamesMin: ['do', 'lu', 'ma', 'mi', 'ju', 'vi', 'sá'],
        monthNames: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
        monthNamesShort: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
        chooseYear: 'Elegir año',
        chooseMonth: 'Elegir mes',
        chooseDate: 'Elegir fecha',
        prevDecade: 'Década anterior',
        nextDecade: 'Década siguiente',
        prevYear: 'Año anterior',
        nextYear: 'Año siguiente',
        prevMonth: 'Mes anterior',
        nextMonth: 'Mes siguiente',
        prevHour: 'Hora anterior',
        nextHour: 'Hora siguiente',
        prevMinute: 'Minuto anterior',
        nextMinute: 'Minuto siguiente',
        prevSecond: 'Segundo anterior',
        nextSecond: 'Segundo siguiente',
        today: 'Hoy',
        weekHeader: 'Sem',
        firstDayOfWeek: 1,
        showMonthAfterYear: false,
        dateFormat: 'dd/mm/yy',
        emptyFilterMessage: 'No se encontraron resultados',
        searchMessage: 'Hay {0} resultados disponibles',
        selectionMessage: 'Se seleccionaron {0} elementos',
        emptySelectionMessage: 'No hay elementos seleccionados',
        emptySearchMessage: 'No se encontraron resultados',
        emptyMessage: 'No hay opciones disponibles',
        aria: {
            pageLabel: 'Página {page}',
            currentPageLabel: 'Página actual, página {page}',
            firstPageLabel: 'Primera página',
            lastPageLabel: 'Última página',
            nextPageLabel: 'Página siguiente',
            prevPageLabel: 'Página anterior',
            rowsPerPageLabel: 'Filas por página',
            jumpToPageDropdownLabel: 'Ir al selector de página',
            jumpToPageInputLabel: 'Ir a la página',
            navigation: 'Navegación',
            previous: 'Anterior',
            next: 'Siguiente',
            showFilterMenu: 'Mostrar filtros',
            hideFilterMenu: 'Ocultar filtros',
            filterOperator: 'Operador de filtro',
            filterConstraint: 'Condición de filtro',
            selectRow: 'Fila seleccionada',
            unselectRow: 'Fila deseleccionada',
        },
    },
});

app.mount('#app');
