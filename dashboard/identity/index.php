<?php
require_once __DIR__ . '/../_app.php';
require_once __DIR__ . '/../_layout.php';

$profile=v3Profile($user_email,$user_name);
$errors=[];

$fields=[
 'first_name'=>'','middle_name'=>'','last_name'=>'','suffix'=>'','gender'=>'','address1'=>'','address2'=>'',
 'apartment_no'=>'','city'=>'','state'=>'','phone_number'=>'','date_of_birth'=>'','zip_code'=>'','us_citizen'=>'',
 'dual_citizenship'=>'','country_of_residence'=>'','source_of_income'=>'','occupation'=>'','nationality'=>'',
 'status'=>'Not submitted'
];
$existingId=null;
$db=connectToDatabase();
$stmt=$db->prepare("SELECT id,first_name,middle_name,last_name,suffix,gender,address1,address2,apartment_no,city,state,
 phone_number,date_of_birth,zip_code,us_citizen,dual_citizenship,country_of_residence,source_of_income,occupation,nationality,status
 FROM kyc_data WHERE email=? ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s',$user_email);
$stmt->execute();
$result=$stmt->get_result();
if($row=$result->fetch_assoc()){
  $existingId=(int)$row['id'];
  foreach($fields as $key=>$_){ if(array_key_exists($key,$row)&&$row[$key]!==null)$fields[$key]=(string)$row[$key]; }
}
$stmt->close();
$db->close();

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['v3_identity_save'])){
  v3VerifyPost();
  foreach(array_keys($fields) as $key){
    if($key==='status') continue;
    $fields[$key]=trim((string)($_POST[$key]??''));
  }
  foreach(['first_name','last_name','address1','city','state','phone_number','date_of_birth','zip_code','country_of_residence','source_of_income','occupation','nationality'] as $key){
    if($fields[$key]==='')$errors[$key]='Required';
  }
  if(empty($errors)){
    $db=connectToDatabase();
    $status='Pending';
    if($existingId){
      $stmt=$db->prepare("UPDATE kyc_data SET first_name=?,middle_name=?,last_name=?,suffix=?,gender=?,address1=?,address2=?,apartment_no=?,
       city=?,state=?,phone_number=?,date_of_birth=?,zip_code=?,us_citizen=?,dual_citizenship=?,country_of_residence=?,
       source_of_income=?,occupation=?,nationality=?,status=?,time_uploaded=NOW() WHERE id=? AND email=?");
      $stmt->bind_param('ssssssssssssssssssssis',
       $fields['first_name'],$fields['middle_name'],$fields['last_name'],$fields['suffix'],$fields['gender'],$fields['address1'],$fields['address2'],$fields['apartment_no'],
       $fields['city'],$fields['state'],$fields['phone_number'],$fields['date_of_birth'],$fields['zip_code'],$fields['us_citizen'],$fields['dual_citizenship'],$fields['country_of_residence'],
       $fields['source_of_income'],$fields['occupation'],$fields['nationality'],$status,$existingId,$user_email);
    }else{
      $stmt=$db->prepare("INSERT INTO kyc_data
       (first_name,middle_name,last_name,suffix,gender,address1,address2,apartment_no,city,state,phone_number,date_of_birth,zip_code,
        us_citizen,dual_citizenship,country_of_residence,source_of_income,occupation,nationality,email,status,time_uploaded)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
      $stmt->bind_param('sssssssssssssssssssss',
       $fields['first_name'],$fields['middle_name'],$fields['last_name'],$fields['suffix'],$fields['gender'],$fields['address1'],$fields['address2'],$fields['apartment_no'],
       $fields['city'],$fields['state'],$fields['phone_number'],$fields['date_of_birth'],$fields['zip_code'],$fields['us_citizen'],$fields['dual_citizenship'],$fields['country_of_residence'],
       $fields['source_of_income'],$fields['occupation'],$fields['nationality'],$user_email,$status);
    }
    $stmt->execute();$stmt->close();
    $stmt=$db->prepare("UPDATE users SET kyc_level=2 WHERE email=?");$stmt->bind_param('s',$user_email);$stmt->execute();$stmt->close();
    recordSecurityEvent($db,$user_email,'KYC Submitted','Identity and profile information submitted for review');
    createUserNotification($db,$user_email,'Identity information received','Your identity and profile information has been submitted for review.','KYC','/dashboard/identity/');
    $db->close();
    v3PostMessage('success','Identity information submitted for review.');
    v3Redirect('/dashboard/identity/');
  }
}

$profile=v3Profile($user_email,$user_name);
v3PageStart('Identity & KYC','identity',$profile,$user_profile_picture);
v3FlashMessages();
?>
<section class="v3-heading">
 <div><span class="v3-kicker">IDENTITY &amp; VERIFICATION</span><h1>Personal information</h1><p>Keep the information used for your banking profile accurate. Changes are submitted for review rather than silently overwriting verified information.</p></div>
</section>
<div class="v3-kyc-note"><strong>Current status:</strong> <?php echo htmlspecialchars($fields['status']); ?>. Required fields are marked by the form structure below. Do not enter information that does not belong to you.</div>
<section class="v3-panel">
 <form method="post" class="v3-form">
  <?php echo v3CsrfInput(); ?>
  <div class="v3-kyc-grid">
   <label><span>First name</span><input name="first_name" required value="<?php echo htmlspecialchars($fields['first_name']); ?>"><?php if(isset($errors['first_name'])):?><small class="v3-status failed">Required</small><?php endif;?></label>
   <label><span>Middle name</span><input name="middle_name" value="<?php echo htmlspecialchars($fields['middle_name']); ?>"></label>
   <label><span>Last name</span><input name="last_name" required value="<?php echo htmlspecialchars($fields['last_name']); ?>"></label>
   <label><span>Suffix / title</span><select name="suffix"><option value="">Select</option><option <?php echo $fields['suffix']==='Mr'?'selected':''; ?>>Mr</option><option <?php echo $fields['suffix']==='Mrs'?'selected':''; ?>>Mrs</option><option <?php echo $fields['suffix']==='Others'?'selected':''; ?>>Others</option></select></label>
   <label><span>Gender</span><select name="gender"><option value="">Select</option><?php foreach(['Male','Female','Others','Not specified'] as $v):?><option value="<?php echo $v;?>" <?php echo $fields['gender']===$v?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select></label>
   <label><span>Date of birth</span><input type="date" name="date_of_birth" required value="<?php echo htmlspecialchars($fields['date_of_birth']); ?>"></label>
   <label><span>Phone number</span><input name="phone_number" required value="<?php echo htmlspecialchars($fields['phone_number']); ?>"></label>
   <label><span>Occupation</span><input name="occupation" required value="<?php echo htmlspecialchars($fields['occupation']); ?>"></label>
   <label class="wide"><span>Residential address</span><input name="address1" required value="<?php echo htmlspecialchars($fields['address1']); ?>"></label>
   <label><span>Address line 2</span><input name="address2" value="<?php echo htmlspecialchars($fields['address2']); ?>"></label>
   <label><span>Apartment / unit</span><input name="apartment_no" value="<?php echo htmlspecialchars($fields['apartment_no']); ?>"></label>
   <label><span>City</span><input name="city" required value="<?php echo htmlspecialchars($fields['city']); ?>"></label>
   <label><span>State / province</span><input name="state" required value="<?php echo htmlspecialchars($fields['state']); ?>"></label>
   <label><span>Postal / ZIP code</span><input name="zip_code" required value="<?php echo htmlspecialchars($fields['zip_code']); ?>"></label>
   <label><span>Country of residence</span><input name="country_of_residence" required value="<?php echo htmlspecialchars($fields['country_of_residence']); ?>"></label>
   <label><span>Nationality</span><input name="nationality" required value="<?php echo htmlspecialchars($fields['nationality']); ?>"></label>
   <label><span>U.S. citizen</span><select name="us_citizen"><option value="">Select</option><?php foreach(['Yes','No'] as $v):?><option value="<?php echo $v;?>" <?php echo $fields['us_citizen']===$v?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select></label>
   <label><span>Dual citizenship</span><select name="dual_citizenship"><option value="">Select</option><?php foreach(['Yes','No'] as $v):?><option value="<?php echo $v;?>" <?php echo $fields['dual_citizenship']===$v?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select></label>
   <label class="wide"><span>Source of income</span><select name="source_of_income" required><option value="">Select source</option><?php foreach(['Investments','Salary','Business','Freelancing','Rental Income','Dividends','Interest','Gifts','Family','Government','Other'] as $v):?><option value="<?php echo $v;?>" <?php echo $fields['source_of_income']===$v?'selected':'';?>><?php echo $v;?></option><?php endforeach;?></select></label>
  </div>
  <div style="display:flex;gap:9px;margin-top:18px;flex-wrap:wrap"><button class="v3-primary-btn" type="submit" name="v3_identity_save" value="1">Submit for review</button><a class="v3-secondary-btn" href="/dashboard/profile/">Cancel</a></div>
 </form>
</section>
<?php v3PageEnd(); ?>