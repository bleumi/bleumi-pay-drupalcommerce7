helpFunction()
{
   echo ""
   echo "Usage: bash $0 -d <absolute path of the root folder of your Drupal Commerce installation>"
   echo "Eg. bash $0 -d /var/www/html/drupal7/commerce"
   exit 1 # Exit script
}

removeFile()
{
   folder=$(dirname $1)
   if test -f "$folder"; then

      if [ ! -w "$folder" ]
      then
         echo "Error: Write permission required on $1"
         exit 1 # Exit script
      fi

      if [ -d "$1" ]; 
      then 
         rm -r $1
      fi

      if test -f "$1"; 
      then
         rm $1
      fi

   fi
}


lastOperationCheck()
{
OUT=$?
if [ ! $OUT -eq 0 ];then
   echo "Copy failed!!! Exiting Installation!!!"
   exit 1 # Exit script 
fi
}

while getopts "d:" opt
do
   case "$opt" in
      d ) DrupalPath="$OPTARG" ;;
      ? ) helpFunction ;; # Print helpFunction in case the parameter is non-existent
   esac
done

# Print helpFunction in case the parameter is empty
if [ -z "$DrupalPath" ]
then
   helpFunction
fi

if [ -d "$DrupalPath" ]; then
  echo "Installing Bleumi Pay Drupal Commerce Extension to ${DrupalPath}"
else
  echo "Error: Drupal Commerce root folder ${DrupalPath} not found."
  exit 1
fi

dir=`pwd`

echo "Begin: Removing any previously deployed Bleumi Pay Drupal Commerce Extension..."
echo "Validating file permissions..."

removeFile $DrupalPath/modules/commerce_bleumipay

echo "End: Removing any previously deployed Bleumi Pay Drupal Commerce Extension..."
echo "Begin: Copying Bleumi Pay Drupal Commerce Extension to ${DrupalPath}"


cp -r $dir/commerce_bleumipay $DrupalPath/modules/
lastOperationCheck

echo "End: Copying Bleumi Pay Drupal Commerce Extension to ${DrupalPath}"
echo "Installation Successful!!!"