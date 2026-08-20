import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as ImagePicker from 'expo-image-picker';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { ChildIdentity, ChildProfile, ChildProfilePhoto } from '@/src/api/types';
import { useAuth } from '@/src/auth/AuthProvider';
import { AuthenticatedImage } from '@/src/components/AuthenticatedImage';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';
import { uploadChildPhoto } from '@/src/uploads/resumable';
import{track}from'@/src/analytics';

type UploadItem = { key: string; asset: ImagePicker.ImagePickerAsset; progress: number; status: 'uploading'|'failed'|'completed'; error?: string; uploadId?: string };
const themes = ['astronaut','doctor','pilot','knight','football_player','princess','explorer'] as const;

export default function IdentityScreen() {
  const { user } = useAuth();
  const { l, isRtl } = useI18n();
  const queryClient = useQueryClient();
  const { data: children } = useQuery({ queryKey:['children'], queryFn:()=>api<{data:ChildProfile[]}>('/children'), enabled:!!user });
  const [childId,setChildId] = useState<string>();
  const { data: photos } = useQuery({ queryKey:['child-photos',childId], queryFn:()=>api<{data:ChildProfilePhoto[]}>(`/children/${childId}/photos`), enabled:!!childId });
  const [selected,setSelected] = useState<string[]>([]);
  const [uploads,setUploads] = useState<UploadItem[]>([]);
  const [identityType,setIdentityType] = useState<'original'|'story_inspired'|'themed'>('original');
  const [theme,setTheme] = useState<string>('astronaut');
  const [consent,setConsent] = useState(false);
  const [reuseConsent,setReuseConsent] = useState(false);

  useEffect(()=>{ if(!childId&&children?.data[0])setChildId(children.data[0].id) },[children,childId]);
  useEffect(()=>{ if(photos?.data&&selected.length===0)setSelected(photos.data.slice(0,3).map(photo=>photo.id)) },[photos,selected.length]);

  const pick = async () => {
    if(!childId||!reuseConsent)return;
    const permission=await ImagePicker.requestMediaLibraryPermissionsAsync();
    if(!permission.granted)return;
    const result=await ImagePicker.launchImageLibraryAsync({mediaTypes:['images'],allowsMultipleSelection:true,selectionLimit:Math.max(1,3-(photos?.data.length??0)),quality:1});
    if(result.canceled)return;
    const items=result.assets.map(asset=>({key:`${asset.assetId??asset.uri}-${Date.now()}`,asset,progress:0,status:'uploading' as const}));
    setUploads(current=>[...current,...items]);
    await Promise.all(items.map(item=>runUpload(item)));
  };
  const runUpload = async (item: UploadItem) => {
    if(!childId)return;
    setUploads(current=>current.map(row=>row.key===item.key?{...row,status:'uploading',error:undefined}:row));
    try {
      const photo=await uploadChildPhoto(childId,item.asset,progress=>setUploads(current=>current.map(row=>row.key===item.key?{...row,progress}:row)),item.uploadId,uploadId=>setUploads(current=>current.map(row=>row.key===item.key?{...row,uploadId}:row)));
      setUploads(current=>current.map(row=>row.key===item.key?{...row,status:'completed',progress:1}:row));
      setSelected(current=>[...new Set([...current,photo.id])].slice(0,3));
      await queryClient.invalidateQueries({queryKey:['child-photos',childId]});
    } catch(error) {
      void track('photo_upload_failed',{child_profile_id:childId});
      setUploads(current=>current.map(row=>row.key===item.key?{...row,status:'failed',error:error instanceof Error?error.message:l('فشل رفع الصورة','Photo upload failed')}:row));
    }
  };
  const createIdentity=useMutation({mutationFn:()=>{void track('identity_generation_started',{child_profile_id:childId,identity_type:identityType});return api<{data:ChildIdentity}>('/child-identities',{method:'POST',body:JSON.stringify({child_profile_id:childId,photo_ids:selected,identity_type:identityType,theme:identityType==='themed'?theme:null,processing_consent:consent,idempotency_key:uuid()})})},onSuccess:response=>router.replace(`/identity/${response.data.id}`)});

  const textAlign={textAlign:isRtl?'right' as const:'left' as const};
  if(!user)return <Screen style={styles.center}><Text style={styles.icon}>✨🦸✨</Text><Text style={[styles.title,textAlign]}>{l('هوية طفلك المجانية','Your free Child Identity')}</Text><Text style={[styles.body,textAlign]}>{l('سجّل الدخول لحفظ الصور والهوية بأمان في ملف طفلك.','Sign in to save photos and the generated identity securely in your child profile.')}</Text><Button title={l('تسجيل الدخول','Sign in')} onPress={()=>router.push('/auth/login')}/></Screen>;
  if(children?.data.length===0)return <Screen style={styles.center}><Text style={styles.icon}>👨‍👩‍👧</Text><Text style={[styles.title,textAlign]}>{l('أنشئ ملف طفلك أولاً','Create your child profile first')}</Text><Button title={l('إضافة طفل','Add child')} onPress={()=>router.push('/children/new')}/></Screen>;
  return <Screen>
    <Text style={[styles.title,textAlign]}>{l('هوية طفلك المجانية','Your free Child Identity')}</Text>
    <Text style={[styles.heading,textAlign]}>{l('١. اختر الطفل','1. Select child')}</Text><View style={styles.choices}>{children?.data.map(child=><Choice key={child.id} label={child.name} active={childId===child.id} onPress={()=>{setChildId(child.id);setSelected([])}}/>)}</View>
    <Text style={[styles.heading,textAlign]}>{l('٢. اختر صورتين أو ثلاث صور واضحة','2. Select two or three clear photos')}</Text><View style={styles.photoGrid}>{photos?.data.map(photo=>{const checked=selected.includes(photo.id);return <Pressable accessibilityLabel={l('صورة الطفل','Child photo')} accessibilityRole="checkbox" accessibilityState={{checked}} key={photo.id} onPress={()=>setSelected(current=>current.includes(photo.id)?current.filter(id=>id!==photo.id):current.length<3?[...current,photo.id]:current)} style={[styles.photo,checked&&styles.photoSelected]}><AuthenticatedImage uri={photo.media_url} accessibilityLabel={l('صورة خاصة للطفل','Private child photo')} style={styles.photoImage} contentFit="cover"/><Text style={styles.check}>{checked?'✓':''}</Text></Pressable>})}</View>
    <Pressable accessibilityLabel={l('الموافقة على حفظ الصور لإعادة استخدامها','Consent to save photos for reuse')} accessibilityRole="checkbox" accessibilityState={{checked:reuseConsent}} onPress={()=>setReuseConsent(value=>!value)} style={styles.consent}><View style={[styles.checkbox,reuseConsent&&styles.checkboxOn]}><Text style={styles.checkboxText}>{reuseConsent?'✓':''}</Text></View><Text style={[styles.consentText,textAlign]}>{l('أوافق على حفظ الصور المختارة بشكل خاص في ملف طفلي لإعادة استخدامها في طلبات HeroKid القادمة. يمكنني حذفها في أي وقت.','I consent to privately save the selected photos in my child profile for future HeroKid orders. I can delete them at any time.')}</Text></Pressable>
    <Button title={l('رفع صور جديدة','Upload new photos')} variant="ghost" disabled={!reuseConsent} onPress={pick}/>{uploads.map(item=><View key={item.key} style={styles.uploadRow}><Text style={[styles.uploadName,textAlign]}>{item.asset.fileName??l('صورة الطفل','Child photo')}</Text><Text style={item.status==='failed'?styles.error:styles.progress}>{item.status==='failed'?l('فشل — أعد المحاولة','Failed — retry'):`${Math.round(item.progress*100)}%`}</Text>{item.status==='failed'?<Button title={l('إعادة المحاولة','Retry')} variant="ghost" onPress={()=>runUpload(item)}/>:null}</View>)}
    <Text style={[styles.heading,textAlign]}>{l('٣. اختر نوع الهوية','3. Choose identity type')}</Text><View style={styles.choices}><Choice label={l('أصلية','Original')} active={identityType==='original'} onPress={()=>setIdentityType('original')}/><Choice label={l('مستوحاة من قصة','Story inspired')} active={identityType==='story_inspired'} onPress={()=>setIdentityType('story_inspired')}/><Choice label={l('بثيم','Themed')} active={identityType==='themed'} onPress={()=>setIdentityType('themed')}/></View>
    {identityType==='themed'?<View style={styles.choices}>{themes.map(value=><Choice key={value} label={themeLabel(value,l)} active={theme===value} onPress={()=>setTheme(value)}/>)}</View>:null}
    <Pressable accessibilityLabel={l('موافقة ولي الأمر على معالجة الصور','Guardian consent to process photos')} accessibilityRole="checkbox" accessibilityState={{checked:consent}} onPress={()=>setConsent(value=>!value)} style={styles.consent}><View style={[styles.checkbox,consent&&styles.checkboxOn]}><Text style={styles.checkboxText}>{consent?'✓':''}</Text></View><Text style={[styles.consentText,textAlign]}>{l('أوافق بصفتي ولي الأمر على معالجة الصور لإنشاء الهوية. الصور خاصة ولن تظهر في التحليلات.','As the guardian, I consent to processing these photos to create the identity. Photos stay private and never appear in analytics.')}</Text></Pressable>
    {createIdentity.error?<Text style={styles.error}>{createIdentity.error.message}</Text>:null}<Button title={l('ابدأ إنشاء الهوية','Generate identity')} disabled={selected.length<2||!consent||createIdentity.isPending} onPress={()=>createIdentity.mutate()}/>
  </Screen>;
}
function Choice({label,active,onPress}:{label:string;active:boolean;onPress:()=>void}){return <Pressable accessibilityLabel={label} accessibilityRole="radio" accessibilityState={{checked:active}} onPress={onPress} style={[styles.choice,active&&styles.choiceOn]}><Text style={[styles.choiceText,active&&styles.choiceTextOn]}>{label}</Text></Pressable>}
function uuid(){return'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return(c==='x'?r:(r&3|8)).toString(16)})}
function themeLabel(value:string,l:(ar:string,en:string)=>string){return({astronaut:l('رائد فضاء','Astronaut'),doctor:l('طبيب','Doctor'),pilot:l('طيار','Pilot'),knight:l('فارس','Knight'),football_player:l('لاعب كرة','Football player'),princess:l('أميرة','Princess'),explorer:l('مستكشف','Explorer')}as Record<string,string>)[value]}
const styles=StyleSheet.create({center:{flexGrow:1,justifyContent:'center'},icon:{fontSize:62,textAlign:'center'},title:{color:colors.text,textAlign:'right',fontSize:30,fontWeight:'900',marginTop:24},body:{color:colors.muted,textAlign:'right',fontSize:17,lineHeight:29},heading:{color:colors.text,textAlign:'right',fontSize:19,fontWeight:'900'},choices:{flexDirection:'row-reverse',flexWrap:'wrap',gap:9},choice:{paddingHorizontal:14,paddingVertical:11,borderRadius:radii.pill,backgroundColor:'#fff',borderWidth:1,borderColor:colors.border},choiceOn:{backgroundColor:colors.primary,borderColor:colors.primary},choiceText:{color:colors.text,fontWeight:'700'},choiceTextOn:{color:'#fff'},photoGrid:{flexDirection:'row-reverse',flexWrap:'wrap',gap:10},photo:{width:94,height:94,borderRadius:radii.md,overflow:'hidden',borderWidth:2,borderColor:'transparent'},photoSelected:{borderColor:colors.primary},photoImage:{width:'100%',height:'100%'},check:{position:'absolute',top:5,right:5,width:24,height:24,borderRadius:12,backgroundColor:colors.primary,color:'#fff',textAlign:'center',fontWeight:'900',overflow:'hidden'},uploadRow:{backgroundColor:'#fff',borderRadius:radii.md,padding:12,gap:6},uploadName:{textAlign:'right',color:colors.text,fontWeight:'700'},progress:{textAlign:'right',color:colors.success},error:{textAlign:'right',color:colors.danger},consent:{flexDirection:'row-reverse',gap:12,alignItems:'flex-start',backgroundColor:'#EEF2FF',borderRadius:radii.md,padding:15},checkbox:{width:25,height:25,borderRadius:7,borderWidth:2,borderColor:colors.primary,alignItems:'center',justifyContent:'center'},checkboxOn:{backgroundColor:colors.primary},checkboxText:{color:'#fff',fontWeight:'900'},consentText:{flex:1,textAlign:'right',color:colors.primaryDark,lineHeight:23}})
