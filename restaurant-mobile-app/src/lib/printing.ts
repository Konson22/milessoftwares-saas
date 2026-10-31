import AsyncStorage from '@react-native-async-storage/async-storage';

/**
 * This phone's printing choice: a kitchen ticket printed each time lines go
 * to the kitchen (on by default). Kept per phone, in either mode.
 */

const KITCHEN_KEY = 'miles-restaurant.print-kitchen';

let printKitchen: boolean | null = null;

export async function printsKitchenTickets(): Promise<boolean> {
  if (printKitchen === null) printKitchen = (await AsyncStorage.getItem(KITCHEN_KEY)) !== 'off';
  return printKitchen;
}

export async function setPrintsKitchenTickets(on: boolean) {
  printKitchen = on;
  await AsyncStorage.setItem(KITCHEN_KEY, on ? 'on' : 'off');
}
