import { createContext, useContext } from '@wordpress/element';

export const NoticesContext = createContext( () => {} );

/** Returns notify( message, status ) for snackbar messages. */
export const useNotify = () => useContext( NoticesContext );
