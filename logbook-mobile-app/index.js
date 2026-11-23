// Gesture handler loads first so the side menu can be swiped. The trip's
// location task is defined before the app starts (ADR-0028), so Android can
// hand positions to it at any time.
import 'react-native-gesture-handler';
import './src/lib/trip-location';
import 'expo-router/entry';
