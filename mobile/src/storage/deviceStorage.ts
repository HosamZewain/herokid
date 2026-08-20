import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

async function secureStoreAvailable(): Promise<boolean> {
  if (Platform.OS === 'web') return false;

  try {
    return await SecureStore.isAvailableAsync();
  } catch {
    return false;
  }
}

export async function getDeviceValue(key: string): Promise<string | null> {
  if (await secureStoreAvailable()) return SecureStore.getItemAsync(key);

  return AsyncStorage.getItem(key);
}

export async function setDeviceValue(key: string, value: string): Promise<void> {
  if (await secureStoreAvailable()) {
    await SecureStore.setItemAsync(key, value);
    return;
  }

  await AsyncStorage.setItem(key, value);
}

export async function deleteDeviceValue(key: string): Promise<void> {
  if (await secureStoreAvailable()) {
    await SecureStore.deleteItemAsync(key);
    return;
  }

  await AsyncStorage.removeItem(key);
}
