import Alpine from 'alpinejs';
import bookingPage from './booking';

window.Alpine = Alpine;

Alpine.data('bookingPage', bookingPage);

Alpine.start();
