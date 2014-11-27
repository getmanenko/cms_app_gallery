<?php
namespace samson\cms\web\gallery;

/**
 * SamsonCMS application for interacting with material gallery
 * @author egorov@samsonos.com
 */
class App extends \samson\cms\App
{
    /** Application name */
    public $name = 'Галлерея';

    /** Hide application access from main menu */
    public $hide = true;

    /** Identifier */
    protected $id = 'gallery';

    private $priority = array();

    /** @see \samson\core\ExternalModule::init()
     * @return bool|void Returns module check result
     */
    public function prepare()
    {
        // TODO: Change this logic to make tab loading more simple
        // Create new gallery tab object to load it
        class_exists(\samson\core\AutoLoader::className('MaterialTab', 'samson\cms\web\gallery'));
    }

    /**
     * Controller for deleting material image from gallery
     * @param string $id Gallery Image identifier
     * @return array Async response array
     */
    public function __async_delete($id)
    {
        // Async response
        $result = array( 'status' => false );

        /** @var \samson\activerecord\gallery $db_image */
        $db_image = null;

        // Find gallery record in DB
        if (dbQuery('gallery')->id($id)->first($db_image)) {
            if ($db_image->Path != '') {
                $upload_dir = $db_image->Path;
                // Physically remove file from server
                if (file_exists($db_image->Path.$db_image->Src)) {
                    unlink($db_image->Path.$db_image->Src);
                }

                /** @var \samson\scale\Scale $scale */
                $scale = m('scale');
                // Delete thumbnails
                if (class_exists('\samson\scale\Scale', false)) {
                    foreach (array_keys($scale->thumnails_sizes) as $folder) {
                        $folder_path = $upload_dir.$folder;
                        if (file_exists($folder_path.'/'.$db_image->Path.$db_image->Src)) {
                            unlink($folder_path.'/'.$db_image->Path.$db_image->Src);
                        }
                    }
                }
            }

            // Remove record from DB
            $db_image->delete();

            $result['status'] = true;
        }

        return $result;
    }

    /**
	 * Controller for rendering gallery images list
	 * @param string $materialId Material identifier
	 * @return array Async response array
	 */
    public function __async_update($materialId)
    {
        return array('status' => true, 'html' => $this->html_list($materialId));
    }

    /**
	 * Controller for image upload
	 * @param string $material_id Material identifier 
	 * @return array Async response array
	 */
    public function __async_upload($material_id)
    {
        // Async response
        s()->async(true);

        $result = array('status' => false);

        /** @var \samson\upload\Upload $upload  Pointer to uploader object */
        $upload = null;
        // Uploading file to server and path current material identifier
        if (uploadFile($upload, array(), $material_id)) {
            /** @var \samson\activerecord\material $material */
            $material = null;
            // Check if participant has not uploaded remix yet
            if (dbQuery('material')->cond('MaterialID', $material_id)->cond('Active', 1)->first($material)) {
                // Create empty db record
                $photo = new \samson\activerecord\gallery(false);
                $photo->Name = $upload->realName();
                $photo->Src = $upload->name();
                $photo->Path = $upload->path();
                $photo->MaterialID = $material->id;
                $photo->size = $upload->size();
                $photo->Active = 1;
                $photo->save();

                if (dbQuery('material')->cond('parent_id', $material->id)->cond('type', 2)->exec($children)) {
                    foreach ($children as $child) {
                        $childPhoto = new \samson\activerecord\gallery(false);
                        $childPhoto->Name = $upload->realName();
                        $childPhoto->Src = $upload->name();
                        $childPhoto->Path = $upload->path();
                        $childPhoto->MaterialID = $child->id;
                        $childPhoto->size = $upload->size();
                        $childPhoto->Active = 1;
                        $childPhoto->save();
                    }
                }

                // Call scale if it is loaded
                if (class_exists('\samson\scale\ScaleController', false)) {
                    /** @var \samson\scale\Scale $scale */
                    $scale = m('scale');
                    $scale->resize($upload->fullPath(), $upload->name(), $upload->uploadDir);
                }

                $result['status'] = true;
            }
        }

        return $result;
    }

    public function __async_priority()
    {
        $result = array('status' => true);

        // If we have changed priority of images
        if (isset($_POST['ids'])) {
            // For each received image id
            for ($i = 0; $i < count($_POST['ids']); $i++) {
                /** @var \samson\activerecord\gallery $photo Variable to store image info */
                $photo = null;
                // If we have such image in database
                if (dbQuery('gallery')->cond('PhotoID', $_POST['ids'][$i])->first($photo)) {
                    // Reset it's priority and save it
                    $photo->priority = $i;
                    $photo->save();
                } else {
                    $result['status'] = false;
                    $result['message'] = 'Can not find images with specified ids!';
                }
            }
        } else {
            $result['status'] = false;
            $result['message'] = 'There are no images to sort!';
        }
        return $result;
    }

    /**
     * Asynchronous function to get image editor
     * @param int $imageId Image identifier to insert into editor
     * @return array Result array
     */
    public function __async_show_edit($imageId)
    {
        /** @var array $result Result of asynchronous controller */
        $result = array('status' => false);
        /** @var \samson\activerecord\gallery $image Image to insert into editor */
        $image = null;
        if (dbQuery('gallery')->cond('PhotoID', $imageId)->first($image)) {

            /** @var string $path Path to image */
            $path = $image->Path . $image->Src;

            // If there is image for this path
            $curlHandle = curl_init(url_build($path));
            curl_setopt($curlHandle, CURLOPT_NOBODY, true);
            curl_setopt($curlHandle, CURLOPT_TIMEOUT, 2);
            curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 2);
            curl_exec($curlHandle);
            if (curl_getinfo($curlHandle, CURLINFO_HTTP_CODE) == 200) {
                $result['status'] = true;
                $result['html'] = $this->view('editor/index')
                    ->set($image, 'image')
                    ->set('path', $path)
                    ->output();
            }
            curl_close($curlHandle);

        }
        return $result;
    }

    /**
     * @param int $imageId Edit image identifier
     * @return array
     */
    public function __async_edit($imageId)
    {
        /** @var array $result Result of asynchronous controller */
        $result = array('status' => false);
        /** @var \samson\activerecord\gallery $image Image to insert into editor */
        $image = null;
        /** @var resource $imageResource Copy of edit image */
        $imageResource = null;
        /** @var resource $croppedImage Resource of cropped image */
        $croppedImage = null;
        if (dbQuery('gallery')->cond('PhotoID', $imageId)->first($image)) {
            $path = getcwd().parse_url(url_build($image->Path . $image->Src), PHP_URL_PATH);

            switch (pathinfo($path, PATHINFO_EXTENSION)) {
                case 'jpeg':
                    $imageResource = imagecreatefromjpeg($path);
                    $croppedImage = $this->cropImage($imageResource);
                    $result['status'] = imagejpeg($croppedImage, $path);
                    break;
                case 'jpg':
                    $imageResource = imagecreatefromjpeg($path);
                    $croppedImage = $this->cropImage($imageResource);
                    $result['status'] = imagejpeg($croppedImage, $path);
                    break;
                case 'png':
                    $imageResource = imagecreatefrompng($path);
                    $croppedImage = $this->cropImage($imageResource);
                    $result['status'] = imagepng($croppedImage, $path);
                    break;
                case 'gif':
                    $imageResource = imagecreatefromgif($path);
                    $croppedImage = $this->cropImage($imageResource);
                    $result['status'] = imagegif($croppedImage, $path);
                    break;
            }

            imagedestroy($croppedImage);
            imagedestroy($imageResource);
        }
        return $result;
    }

    /**
     * Render gallery images list
     * @param string $material_id Material identifier
     * @return string html representation of image list
     */
    public function html_list($material_id)
    {
        // Get all material images
        $items_html = '';
        $images = array();
        if (dbQuery('gallery')->cond('MaterialID', $material_id)->order_by('priority')->exec($images)) {
            foreach ($images as $image) {
                // Get image size string
                $size = ', ';
                // Get old-way image path, remove full path to check file
                if (empty($image->Path)) {
                    $path = $image->Src;
                } else { // Use new CORRECT way
                    $path = $image->Path . $image->Src;
                }

                /*$ch = curl_init(url_build($path));
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                curl_exec($ch);
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
                    $path = 'img/no-img.png';
                }
                curl_close($ch);*/

                // set image size string representation, if it is not 0
                $size = ($image->size == 0) ? '' : $size . $this->humanFileSize($image->size);

                //Set priority array
                $this->priority[$image->priority] = $image->PhotoID;

                // Render gallery image tumb
                $items_html .= $this->view('tumbs/item')
                    ->set($image, 'image')
                    ->set('name', utf8_limit_string($image->Name, 18, '...'))
                    ->set('imgpath', $path)
                    ->set('size', $size)
                    ->set('material_id', $material_id)
                    ->output();
            }
        }

        // Render content into inner content html
        return $this->view('tumbs/index')
            ->set('images', $items_html)
            ->set('material_id', $material_id)
        ->output();
    }

    public function humanFileSize($bytes, $decimals = 2) {
        $sizeLetters = 'BKBMBGBTBPB';
        $factor = (int)(floor((strlen($bytes) - 1) / 3));
        $sizeLetter = ($factor <= 0) ? substr($sizeLetters, 0, 1) : substr($sizeLetters, $factor * 2 - 1, 2);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . $sizeLetter;
    }


    /**
     * Function to reduce code size in __async_edit method
     * @param $imageResource
     * @return bool|resource
     */
    public function cropImage($imageResource)
    {
        $rotatedImage = imagerotate($imageResource, -($_POST['rotate']), hexdec('FFFFFF'));
        $croppedImage = imagecrop($rotatedImage, array('x' => $_POST['crop_x'],
            'y' => $_POST['crop_y'],
            'width' => $_POST['crop_width'],
            'height' => $_POST['crop_height']));
        imagedestroy($rotatedImage);
        return $croppedImage;
    }
}