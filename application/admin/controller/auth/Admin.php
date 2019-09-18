<?php

namespace app\admin\controller\auth;

use app\admin\model\AuthGroup;
use app\admin\model\AuthGroupAccess;
use app\common\controller\Backend;
use fast\Random;
use fast\Tree;

/**
 * 管理员管理
 *
 * @icon fa fa-users
 * @remark 一个管理员可以有多个角色组,左侧的菜单根据管理员所拥有的权限进行生成
 * @author ^2_3^
 */
class Admin extends Backend
{

    /**
     * @var \app\admin\model\Admin
     */
    protected $model = null;

    protected $childrenGroupIds = [];
    protected $childrenAdminIds = [];

    /**
     * 初始化
     * @author ^2_3^
     */
    public function _initialize()
    {
        parent::_initialize();

        // 管理员模型
        $this->model = model('Admin');

        // 名下管理员
        $this->childrenAdminIds = $this->auth->getChildrenAdminIds(true);
        // 名下分组
        $this->childrenGroupIds = $this->auth->getChildrenGroupIds(true);

        // 分组信息
        $groupList = collection(
            AuthGroup::where('id', 'in', $this->childrenGroupIds)->select()
        )->toArray();

        Tree::instance()->init($groupList);
        $groupdata = [];
        if ($this->auth->isSuperAdmin())
        {
            $result = Tree::instance()->getTreeList(Tree::instance()->getTreeArray(0));
            foreach ($result as $k => $v)
            {
                $groupdata[$v['id']] = $v['name'];
            }
        }
        else
        {
            $result = [];
            $groups = $this->auth->getGroups();
            foreach ($groups as $m => $n)
            {
                $childlist = Tree::instance()->getTreeList(Tree::instance()->getTreeArray($n['id']));

                $temp = [];
                foreach ($childlist as $k => $v)
                {
                    $temp[$v['id']] = $v['name'];
                }
                $result[__($n['name'])] = $temp;
            }
            $groupdata = $result;
        }

        $this->view->assign('groupdata', $groupdata);
        $this->assignconfig("admin", ['id' => $this->auth->id]);
    }

    /**
     * 查看
     * @author ^2_3^
     */
    public function index()
    {
        if ($this->request->isAjax())
        {
            // 如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField'))
            {
                return $this->selectpage();
            }

            //// 名下分组
            $childrenGroupIds = $this->childrenGroupIds;
            $groupName = AuthGroup::where('id', 'in', $childrenGroupIds)
                    ->column('id,name');

            // 名下管理员
            $authGroupList = AuthGroupAccess::where('group_id', 'in', $childrenGroupIds)
                    ->field('uid,group_id')
                    ->select();

            $adminGroupName = [];
            foreach ($authGroupList as $k => $v)
            {
                if (isset($groupName[$v['group_id']]))
                    $adminGroupName[$v['uid']][$v['group_id']] = $groupName[$v['group_id']];
            }

            $groups = $this->auth->getGroups();
            foreach ($groups as $m => $n)
            {
                $adminGroupName[$this->auth->id][$n['id']] = $n['name'];
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $total = $this->model
                    ->where($where)
                    ->where('id', 'in', $this->childrenAdminIds)
                    ->order($sort, $order)
                    ->count();

            $list = $this->model
                    ->where($where)
                    ->where('id', 'in', $this->childrenAdminIds)
                    ->field(['password', 'salt', 'token'], true)
                    ->order($sort, $order)
                    ->limit($offset, $limit)
                    ->select();

            foreach ($list as $k => &$v)
            {
                $groups = isset($adminGroupName[$v['id']]) ? $adminGroupName[$v['id']] : [];
                $v['groups'] = implode(',', array_keys($groups));
                $v['groups_text'] = implode(',', array_values($groups));
            }
            unset($v);

            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 添加
     * @author ^2_3^
     */
    public function add()
    {
        if ($this->request->isPost())
        {
            // 添加参数
            $params = $this->request->post("row/a");

            if ($params)
            {
                // 密码盐
                $params['salt'] = Random::alnum();
                // 密码
                $params['password'] = md5(md5($params['password']) . $params['salt']);
                // 头像
                $params['avatar'] = '/assets/img/avatar.png'; //设置新管理员默认头像。

                // 数据验证(调用Admin验证器类进行数据验证)
                $result = $this->model->validate('Admin.add')->save($params);
                if ($result === false)
                {
                    $this->error($this->model->getError());
                }

                // 所属组别
                $group = $this->request->post("group/a");

                //过滤不允许的组别,避免越权
                $group = array_intersect($this->childrenGroupIds, $group);

                // 添加管理员分组信息
                $dataset = [];
                foreach ($group as $value)
                {
                    $dataset[] = ['uid' => $this->model->id, 'group_id' => $value];
                }
                model('AuthGroupAccess')->saveAll($dataset);

                $this->success();
            }
            $this->error();
        }
        return $this->view->fetch();
    }

    /**
     * 编辑
     * @author ^2_3^
     */
    public function edit($ids = NULL)
    {
        $row = $this->model->get(['id' => $ids]);
        if (!$row){
            $this->error(__('No Results were found'));
        }

        if ($this->request->isPost())
        {
            // 编辑参数
            $params = $this->request->post("row/a");
            if ($params)
            {
                // 密码存在则修改
                if ($params['password'])
                {
                    $params['salt'] = Random::alnum();
                    $params['password'] = md5(md5($params['password']) . $params['salt']);
                }
                else
                {
                    unset($params['password'], $params['salt']);
                }

                //// 数据验证
                // 这里需要针对username和email做唯一验证
                $adminValidate = \think\Loader::validate('Admin');
                $adminValidate->rule([
                    'username' => 'require|max:50|unique:admin,username,' . $row->id,
                    'email'    => 'require|email|unique:admin,email,' . $row->id
                ]);
                $result = $row->validate('Admin.edit')->save($params);
                if ($result === false)
                {
                    $this->error($row->getError());
                }

                // 先移除所有权限
                model('AuthGroupAccess')->where('uid', $row->id)->delete();

                // 所属组别(允许多个)
                $group = $this->request->post("group/a");

                // 过滤不允许的组别,避免越权
                $group = array_intersect($this->childrenGroupIds, $group);

                //// 添加管理员分组信息
                $dataset = [];
                foreach ($group as $value)
                {
                    $dataset[] = ['uid' => $row->id, 'group_id' => $value];
                }
                model('AuthGroupAccess')->saveAll($dataset);

                $this->success();
            }
            $this->error();
        }

        // 所属分组
        $grouplist = $this->auth->getGroups($row['id']);
        $groupids = [];
        foreach ($grouplist as $k => $v)
        {
            $groupids[] = $v['id'];
        }

        $this->view->assign("row", $row);
        $this->view->assign("groupids", $groupids);
        return $this->view->fetch();
    }

    /**
     * 删除
     * @author ^2_3^
     */
    public function del($ids = "")
    {
        if ($ids)
        {
            // 避免越权删除管理员
            $childrenGroupIds = $this->childrenGroupIds;
            $adminList = $this->model->where('id', 'in', $ids)
                ->where('id', 'in', function($query) use($childrenGroupIds) {
                        $query->name('auth_group_access')->where('group_id', 'in', $childrenGroupIds)->field('uid');
                    })->select();

            if ($adminList)
            {
                $deleteIds = [];
                foreach ($adminList as $k => $v)
                {
                    $deleteIds[] = $v->id;
                }

                $deleteIds = array_diff($deleteIds, [$this->auth->id]);
                if ($deleteIds)
                {
                    // 删除管理员
                    $this->model->destroy($deleteIds);

                    // 删除管理员分组信息
                    model('AuthGroupAccess')->where('uid', 'in', $deleteIds)->delete();
                    $this->success();
                }
            }
        }
        $this->error();
    }

    /**
     * 批量更新
     * @internal
     * @author ^2_3^
     */
    public function multi($ids = "")
    {
        // 管理员禁止批量操作
        $this->error();
    }

    /**
     * 下拉搜索
     * @author ^2_3^
     */
    protected function selectpage()
    {
        // 数据限制类型(auth/persion)
        $this->dataLimit = 'auth';
        // 数据限制字段
        $this->dataLimitField = 'id';
        return parent::selectpage();
    }

}