import { deleteDeviceValue, getDeviceValue, setDeviceValue } from '@/src/storage/deviceStorage';
import Constants from'expo-constants';
import { Platform } from 'react-native';
import{getInstallationId}from'@/src/device/installation';
const localHost=Platform.OS==='android'?'10.0.2.2':'localhost'; export const API_URL=process.env.EXPO_PUBLIC_API_URL??`http://${localHost}:8088/api/v1`; const TOKEN_KEY='herokid.auth.token';
export class ApiError extends Error{constructor(message:string,public status:number,public fields?:Record<string,string[]>){super(message)}}
export async function setToken(token:string|null){if(token)await setDeviceValue(TOKEN_KEY,token);else await deleteDeviceValue(TOKEN_KEY)}
export const getToken=()=>getDeviceValue(TOKEN_KEY);
export async function api<T>(path:string,init:RequestInit={}):Promise<T>{const[token,installation]=await Promise.all([getToken(),getInstallationId()]);const isForm=typeof FormData!=='undefined'&&init.body instanceof FormData;const response=await fetch(`${API_URL}${path}`,{...init,headers:{Accept:'application/json','X-Device-Installation':installation,'X-App-Version':Constants.expoConfig?.version??'unknown','X-Platform':Platform.OS,...(!isForm?{'Content-Type':'application/json'}:{}),...(token?{Authorization:`Bearer ${token}`}:{}) ,...init.headers}});const body=response.status===204?null:await response.json().catch(()=>null);if(!response.ok)throw new ApiError(body?.message??'Request failed',response.status,body?.errors);return body as T}
