"""Run only in the authorized AWS admin CloudShell, after IAM approval.
Creates isolated staging resources and replaces the named instance profile.
Does not alter production bucket policies or DNS. No static credentials.
"""
import boto3, json, time

account = boto3.client('sts').get_caller_identity()['Account']
assert account == '645314607155'
region = 'eu-central-1'
bucket = 'herokid-staging-media-645314607155'
role = 'HeroKid-Staging-EC2-S3-Role'
instance = 'i-00fea72c8abdc72f3'
s3 = boto3.client('s3', region_name=region)
iam = boto3.client('iam')
ec2 = boto3.client('ec2', region_name=region)
try:
    s3.create_bucket(Bucket=bucket, CreateBucketConfiguration={'LocationConstraint': region})
except s3.exceptions.BucketAlreadyOwnedByYou:
    pass
s3.put_public_access_block(Bucket=bucket, PublicAccessBlockConfiguration={k: True for k in ['BlockPublicAcls','IgnorePublicAcls','BlockPublicPolicy','RestrictPublicBuckets']})
s3.put_bucket_ownership_controls(Bucket=bucket, OwnershipControls={'Rules':[{'ObjectOwnership':'BucketOwnerEnforced'}]})
s3.put_bucket_encryption(Bucket=bucket, ServerSideEncryptionConfiguration={'Rules':[{'ApplyServerSideEncryptionByDefault':{'SSEAlgorithm':'AES256'}}]})
s3.put_bucket_versioning(Bucket=bucket, VersioningConfiguration={'Status':'Enabled'})
arn = 'arn:aws:s3:::'+bucket
s3.put_bucket_policy(Bucket=bucket, Policy=json.dumps({'Version':'2012-10-17','Statement':[{'Sid':'RequireTLS','Effect':'Deny','Principal':'*','Action':'s3:*','Resource':[arn,arn+'/*'],'Condition':{'Bool':{'aws:SecureTransport':'false'}}}]}))
trust={'Version':'2012-10-17','Statement':[{'Effect':'Allow','Principal':{'Service':'ec2.amazonaws.com'},'Action':'sts:AssumeRole'}]}
try:
    iam.create_role(RoleName=role, AssumeRolePolicyDocument=json.dumps(trust), Description='HeroKid isolated staging only; no production bucket access')
except iam.exceptions.EntityAlreadyExistsException:
    current=iam.get_role(RoleName=role)['Role']
    assert current['AssumeRolePolicyDocument']==trust, 'Unexpected existing role trust'
policy={'Version':'2012-10-17','Statement':[
 {'Effect':'Deny','Action':'s3:*','NotResource':[arn,arn+'/*']},
 {'Effect':'Allow','Action':['s3:GetBucketLocation'],'Resource':arn},
 {'Effect':'Allow','Action':['s3:ListBucket'],'Resource':arn,'Condition':{'StringLike':{'s3:prefix':['private','private/*']}}},
 {'Effect':'Allow','Action':['s3:GetObject','s3:PutObject','s3:DeleteObject','s3:AbortMultipartUpload','s3:ListMultipartUploadParts'],'Resource':arn+'/private/*'}
]}
iam.put_role_policy(RoleName=role, PolicyName='StagingPrivateMediaOnly', PolicyDocument=json.dumps(policy))
try:
    iam.create_instance_profile(InstanceProfileName=role)
except iam.exceptions.EntityAlreadyExistsException:
    pass
profile=iam.get_instance_profile(InstanceProfileName=role)['InstanceProfile']
assert all(r['RoleName']==role for r in profile['Roles'])
if not profile['Roles']:
    iam.add_role_to_instance_profile(InstanceProfileName=role, RoleName=role)
time.sleep(12)
associations=ec2.describe_iam_instance_profile_associations(Filters=[{'Name':'instance-id','Values':[instance]}])['IamInstanceProfileAssociations']
assert len(associations)==1
old=associations[0]
assert old['IamInstanceProfile']['Arn'].split('/')[-1] in ['HeroKid-Prod-EC2-S3-Role',role]
print('Previous instance profile:',old['IamInstanceProfile']['Arn'])
if old['IamInstanceProfile']['Arn'].split('/')[-1]!=role:
    ec2.replace_iam_instance_profile_association(AssociationId=old['AssociationId'], IamInstanceProfile={'Name':role})
print('STAGING_BUCKET_ROLE_CONFIGURED',bucket,role,instance)
